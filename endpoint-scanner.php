<?php
require 'vendor/autoload.php';

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Stmt\ClassMethod;

$plugin_path = __DIR__;
$output_dir = __DIR__ . '/endpoint-reports';

// Create output directory
if (!file_exists($output_dir)) {
    mkdir($output_dir, 0755, true);
}

ini_set('memory_limit', '1256M');

class ClassTrackerVisitor extends NodeVisitorAbstract {
    public $currentClass = null;
    
    public function enterNode(Node $node) {
        if ($node instanceof Node\Stmt\Class_) {
            $this->currentClass = $node->name->toString();
            print("Detectada classe: {$this->currentClass}\n");
            if ($this->currentClass === 'Abstract_API') {
                throw new \RuntimeException('Skipping Abstract_API class');
            }
        }
    }
    
    public function leaveNode(Node $node) {
        if ($node instanceof Node\Stmt\Class_) {
            #$this->currentClass = null;
        }
    }
}

$parser = (new ParserFactory)->createForNewestSupportedVersion();
$nodeFinder = new NodeFinder;
$traverser = new NodeTraverser;
$classTracker = new ClassTrackerVisitor();
$traverser->addVisitor(new ParentConnectingVisitor());
$traverser->addVisitor($classTracker);

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($plugin_path)
);

foreach ($iterator as $file) {
    if ($file->isDir() || $file->getExtension() !== 'php') continue;
    
    try {
        $current_file = $file->getPathname();
        $code = file_get_contents($current_file);
        $ast = $parser->parse($code);
        
        $classTracker->currentClass = null; // Resetar apenas antes de processar um novo arquivo
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new ParentConnectingVisitor());
        $traverser->addVisitor($classTracker);
        
        try {
            $ast = $traverser->traverse($ast);
            print("Classe detectada neste arquivo: " . ($classTracker->currentClass ?: 'Nenhuma') . "\n");
        } catch (\Throwable $e) {
            echo "Skipping file due to memory issue: {$current_file}\n";
            continue;
        }
        
        // Process REST Endpoints
        $restRoutes = $nodeFinder->find($ast, function($node) {
            return $node instanceof Node\Expr\FuncCall &&
                   $node->name instanceof Node\Name &&
                   $node->name->toString() === 'register_rest_route';
        });

        foreach ($restRoutes as $routeCall) {
            $args = $routeCall->args;
            
            $endpoint = [
                'type' => 'rest',
                'namespace' => get_argument_value($args[0]->value, $classTracker->currentClass),
                'route' => get_argument_value($args[1]->value, $classTracker->currentClass),
                'options' => isset($args[2]) ? parse_options($args[2]->value, $classTracker->currentClass) : [],
                'file' => $current_file,
                'line' => $routeCall->getStartLine(),
                'registration_code' => extract_node_code($routeCall, $current_file),
                'callback' => null
            ];

            if (!empty($endpoint['options']['callback'])) {
                $endpoint['callback'] = $endpoint['options']['callback'];
                $callback_code = find_callback_source(
                    $endpoint['callback'], 
                    $current_file, 
                    $ast
                );
                
                $filename = sanitize_filename("rest-{$endpoint['namespace']}-{$endpoint['route']}.txt");
                file_put_contents(
                    "$output_dir/$filename",
                    "=== REST Endpoint ===\n" .
                    "Namespace: {$endpoint['namespace']}\n" .
                    "Route: {$endpoint['route']}\n" .
                    "Methods: " . ($endpoint['options']['methods'] ?? 'GET') . "\n" .
                    "File: {$endpoint['file']} (Line: {$endpoint['line']})\n\n" .
                    "=== Registration Code ===\n{$endpoint['registration_code']}\n\n" .
                    "=== Callback Implementation ===\n" . $callback_code . "\n"
                );
            }
        }

        // Process AJAX Endpoints
        $ajaxHooks = $nodeFinder->find($ast, function($node) {
            return $node instanceof Node\Expr\FuncCall &&
                   $node->name instanceof Node\Name &&
                   $node->name->toString() === 'add_action' &&
                   count($node->args) >= 2 &&
                   is_ajax_hook($node->args[0]->value);
        });
        
        print("Número de hooks AJAX detectados: " . count($ajaxHooks) . "\n");

        foreach ($ajaxHooks as $ajaxCall) {
            $hook = get_argument_value($ajaxCall->args[0]->value, $classTracker->currentClass);
            $callbackNode = $ajaxCall->args[1]->value;
            
            $endpoint = [
                'type' => 'ajax',
                'action' => str_replace(['wp_ajax_', 'wp_ajax_nopriv_'], '', $hook),
                'callback' => parse_callback($callbackNode, $classTracker->currentClass),
                'privilege' => str_starts_with($hook, 'wp_ajax_nopriv_') ? 'public' : 'private',
                'file' => $current_file,
                'line' => $ajaxCall->getStartLine(),
                'registration_code' => extract_node_code($ajaxCall, $current_file),
                'callback_code' => find_callback_source(
                    parse_callback($callbackNode, $classTracker->currentClass), 
                    $current_file, 
                    $ast
                )
            ];

            // create a random integer for the filename
            $random_int = random_int(1000, 9999);
            $filename = sanitize_filename("ajax-{$endpoint['action']}-{$endpoint['privilege']}-{$random_int}.txt");
            echo "Gerando arquivo: $filename\n";
            file_put_contents(
                "$output_dir/$filename",
                "=== AJAX Endpoint ===\n" .
                "Action: {$endpoint['action']}\n" .
                "Access: {$endpoint['privilege']}\n" .
                "Callback: {$endpoint['callback']}\n" .
                "File: {$endpoint['file']} (Line: {$endpoint['line']})\n\n" .
                "=== Registration Code ===\n{$endpoint['registration_code']}\n\n" .
                "=== Callback Implementation ===\n{$endpoint['callback_code']}\n"
            );
        }

    } catch (Error $error) {
        echo "Parse error in {$current_file}: {$error->getMessage()}\n";
    } catch (\RuntimeException $e) {
        if ($e->getMessage() === 'Skipping Abstract_API class') {
            echo "Skipping Abstract_API class in file: {$current_file}\n";
            continue;
        }
        throw $e; // Re-throw other runtime exceptions
    }
}

// Helper functions
function is_ajax_hook($node) {
    $hook = get_argument_value($node);
    return str_starts_with($hook, 'wp_ajax_') || str_starts_with($hook, 'wp_ajax_nopriv_');
}

function parse_callback($node, $currentClass = null) {
    if ($node instanceof Node\Expr\Array_) {
        if (count($node->items) === 2) {
            $classPart = get_argument_value($node->items[0]->value, $currentClass);
            $methodPart = get_argument_value($node->items[1]->value, $currentClass);

            if (($classPart === '$this' || $classPart === 'self::class') && $currentClass) {
                return "{$currentClass}::{$methodPart}";
            }
            
            return $classPart && $methodPart ? "{$classPart}::{$methodPart}" : '[Array Callback]';
        }
        // ⚠️ Fallback to prevent infinite loop
        return '[Array Callback]';
    }
    echo $currentClass;
    return get_argument_value($node, $currentClass);
}

function get_argument_value($node, $currentClass = null) {
    if ($node instanceof Node\Expr\Array_) {
        return parse_callback($node, $currentClass);
    }
    
    if ($node instanceof Node\Expr\Variable && $node->name === 'this') {
        return $currentClass ?: '$this';
    }
    
    if ($node instanceof Node\Expr\ClassConstFetch) {
        $class = $node->class instanceof Node\Name ? $node->class->toString() : '[Dynamic]';
        return "{$class}::{$node->name->toString()}";
    }
    
    if ($node instanceof Node\Expr\BinaryOp\Concat) {
        return get_argument_value($node->left, $currentClass) . 
               get_argument_value($node->right, $currentClass);
    }
    
    if ($node instanceof Node\Scalar\String_) {
        return $node->value;
    }
    
    return '[Dynamic Value]';
}

function extract_node_code($node, $filepath) {
    $start = $node->getStartLine();
    $end = $node->getEndLine();
    
    if (file_exists($filepath) && is_readable($filepath)) {
        $lines = file($filepath);
        return implode("", array_slice($lines, $start - 1, $end - $start + 1));
    }
    
    return "Source unavailable";
}

function find_callback_source($callback, $filepath, $ast) {
    $nodeFinder = new NodeFinder;
    
    if (str_contains($callback, '::')) {
        [$class, $method] = explode('::', $callback);
        $nodes = $nodeFinder->find($ast, function($node) use ($class, $method) {
            return $node instanceof ClassMethod &&
                   $node->name->toString() === $method &&
                   $node->getAttribute('parent')->name->toString() === $class;
        });
    } else {
        $nodes = $nodeFinder->find($ast, function($node) use ($callback) {
            return $node instanceof Node\Stmt\Function_ &&
                   $node->name->toString() === $callback;
        });
    }

    return !empty($nodes) ? extract_node_code($nodes[0], $filepath) : "Callback not found";
}

function sanitize_filename($name) {
    return preg_replace('/[^a-zA-Z0-9\-_]/', '_', $name) . '.txt';
}

function parse_options($node, $currentClass) {
    $options = [];
    if ($node instanceof Node\Expr\Array_) {
        foreach ($node->items as $item) {
            if (!$item->key instanceof Node\Scalar\String_) continue;
            $options[$item->key->value] = get_argument_value($item->value, $currentClass);
        }
    }
    return $options;
}