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
    if (method_exists(ScheduleGenerator::class, 'sort_classes_by_prerequisite')) {
        $sorted_results = ScheduleGenerator::sort_classes_by_prerequisite($results, ScheduleGenerator::get_desired_credit_hours($node), false);
        echo "------------------------------------------------\n";
        echo "Sorted Results by Course Prerequisite:\n";
        print_r($sorted_results);

        if (method_exists(ScheduleGenerator::class, 'save_schedule_to_node')) {
            $input = readline("Do you want to save this schedule to the node? (y/n): ");
            if (strtolower(trim($input)) === 'y') {

                echo "Saving schedule to node...\n";

                ScheduleGenerator::save_schedule_to_node($node, $sorted_results);

                echo "Save complete!\n";
            } else {
                echo "Operation skipped.\n";
            }
        }
    } else {
        echo "Function 'sort_classes_by_prerequisite' not found.\n";
    }
} else {
    echo "Function 'get_all_classes' not found. Make sure the module is enabled.\n";
}

if (method_exists(ScheduleGenerator::class, 'get_upcoming_semesters')) {
    $semesters = ScheduleGenerator::get_upcoming_semesters(12);
    foreach ($semesters as $semester) {
        echo $semester->label();
        echo " ";
    }
}
