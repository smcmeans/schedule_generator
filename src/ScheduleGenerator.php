<?php

namespace Drupal\schedule_generator;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;

class ScheduleGenerator {

  protected $entityTypeManager;
  protected $studentProfileManager;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, StudentProfileManager $studentProfileManager) {
    $this->entityTypeManager = $entityTypeManager;
    $this->studentProfileManager = $studentProfileManager;
  }

  public static function get_all_classes(NodeInterface $studentProfile) {
    $courses = [];

    echo "DEBUG: Starting search for Student Node ID: " . $studentProfile->id() . "\n";

    // Check if the 'field_majors' field actually exists on this node type
    if (!$studentProfile->hasField('field_majors')) {
        echo "ERROR: The field 'field_majors' does not exist on this node type.\n";
        return [];
    }

    // Check if the field has data
    if ($studentProfile->get('field_majors')->isEmpty()) {
        echo "DEBUG: The 'field_majors' is empty for this student.\n";
    } else {
        $majors = $studentProfile->get('field_majors')->referencedEntities();
        echo "DEBUG: Found " . count($majors) . " major(s).\n";

        foreach ($majors as $term) {
            echo "DEBUG: Checking Major: " . $term->label() . " (ID: " . $term->id() . ")\n";

            // Check if the Major term has the 'field_all_classes' field
            if (!$term->hasField('field_all_classes')) {
                echo "ERROR: The field 'field_all_classes' does not exist on taxonomy term " . $term->id() . "\n";
                continue;
            }

            if ($term->get('field_all_classes')->isEmpty()) {
                echo "DEBUG: Major '" . $term->label() . "' has no classes linked in 'field_all_classes'.\n";
            } else {
                $taxonomy = $term->get('field_all_classes')->referencedEntities();
                echo "DEBUG: Found " . count($taxonomy) . " class(es) in this major.\n";
                
                foreach ($taxonomy as $class_entity) {
                    $courses[$class_entity->id()] = [
                      'title' => $class_entity->label(),
                      // Wrap these in checks to avoid crashes if fields are empty
                      'number' => $class_entity->hasField('field_course_number') ? $class_entity->get('field_course_number')->value : 'N/A',
                      'credits' => $class_entity->hasField('field_credit_hours') ? $class_entity->get('field_credit_hours')->value : '0',
                    ]; 
                }
            }
        }
    }

    foreach ($minors as $term) {
      // This loops through each minor
      // We want to get the taxonomy associated with the minor, and then all the courses for that taxonomy
      $taxonomy = $term->get('field_all_classes')->referencedEntities();
      
      foreach ($taxonomy as $class_entity) {
        // Add by id to avoid duplicates
        $courses[$class_entity->id()] = [
          'title' => $class_entity->getTitle(),
          'number' => $class_entity->get('field_course_number')->value,
          'credits' => $class_entity->get('field_credit_hours')->value,
          'prerequisite' => $class_entity->get('field_prerequisite')->value,
        ];
      }
    }

    echo "DEBUG: Total unique courses collected: " . count($courses) . "\n";
    return array_values($courses);
  }

}