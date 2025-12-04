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
    // Additionally, test sorting by prerequisites
    if (method_exists(ScheduleGenerator::class, 'sort_courses_by_number')) {
        $sorted_results = ScheduleGenerator::sort_courses_by_number($results);
        echo "------------------------------------------------\n";
        echo "Sorted Results by Course Number:\n";
        print_r($sorted_results);
    } else {
        echo "Function 'sort_courses_by_number' not found.\n";
    }
} else {
    echo "Function 'get_all_classes' not found. Make sure the module is enabled.\n";
}