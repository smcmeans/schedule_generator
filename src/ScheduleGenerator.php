<?php

namespace Drupal\schedule_generator;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;

class ScheduleGeneratorService {

  protected $entityTypeManager;
  protected $studentProfileManager;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, StudentProfileManager $studentProfileManager) {
    $this->entityTypeManager = $entityTypeManager;
    $this->studentProfileManager = $studentProfileManager;
  }

  public static function get_all_classes(NodeInterface $studentProfile) {
    $courses = [];

    $majors = $studentProfile->get('field_majors')->referencedEntities(); // Returns a list
    $minors = $studentProfile->get('field_minors')->referencedEntities(); // Returns a list

    // Fetch the taxonomy terms for majors and minors.
    foreach ($majors as $term) {
      // This loops through each major
      // We want to get the taxonomy associated with the major, and then all the courses for that taxonomy
      $taxonomy = $term->get('field_all_classes')->referencedEntities();
      
      foreach ($taxonomy as $class) {
        // Add by id to avoid duplicates
        $courses[$class_entity->id()] = [
          'title' => $class_entity->getTitle(),
          'number' => $class_entity->get('field_course_number')->value,
          'credits' => $class_entity->get('field_credit_hours')->value,
          'prerequisite' => $class_entity->get('field_prerequisite')->value,
        ]
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
        ]
      }
    }

    // Array_values to reindex the array numerically (instead of by course ID)
    return array_values($courses);
  }

}