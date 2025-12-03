<?php

use \Drupal\node\Entity\Node;
use Drupal\schedule_generator\ScheduleGenerator;

// Hardcoded nid for testing
$nid = 23; // John Doe's student profile node ID
$node = Node::load($nid);

if (!$node) {
    echo "Node $nid not found.\n";
    exit;
}

echo "Testing with Student: " . $node->label() . "\n";
echo "------------------------------------------------\n";

if (method_exists(ScheduleGenerator::class, 'get_all_classes')) {
    $results = ScheduleGenerator::get_all_classes($node);
    
    // Print results
    print_r($results);
} else {
    echo "Function 'get_all_classes' not found. Make sure the module is enabled.\n";
}