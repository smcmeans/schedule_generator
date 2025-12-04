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

    $majors = $studentProfile->get('field_majors')->referencedEntities();
    $minors = $studentProfile->get('field_minors')->referencedEntities();

    $programs = array_merge($majors, $minors);
    
    foreach ($programs as $program) {
      if ($program->hasField('field_requirement_groups')) {
        // Find outer paragraph of each major
        $requirement_groups = $program->get('field_requirement_groups')->referencedEntities();
        foreach ($requirement_groups as $options) {
          // Outer paragraph loop

          // Find inner paragraphs of each requirement group
          $requirement_options = $options->get('field_requirement_options')->referencedEntities();
          foreach ($requirement_options as $option) {
            // Inner paragraph loop

            // Get courses from each option
            if ($option->hasField('field_option_courses')) {
              $course_entities = $option->get('field_option_courses')->referencedEntities();
              foreach ($course_entities as $course) {
                $courses[$course->id()] = [
                  'title' => $course->label(),
                  'number' => $course->get('field_course_number')->value,
                  'credits' => $course->get('field_credit_hours')->value,
                  'prerequisite' => $course->get('field_prerequisite')->value,
                ];
              }
            }
          }
        }
      }
    }


  }
}