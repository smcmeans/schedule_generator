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

                // Add labs/recitations if they exist
                // Might want to move this to the final schedule generation command to ensure linked sections are not split
                // across semesters
                if ($course->hasField('field_linked_sections') && !$course->get('field_linked_sections')->isEmpty()) {
                  $linked_sections = $course->get('field_linked_sections')->referencedEntities();
                  foreach ($linked_sections as $section) {
                    $courses[$section->id()] = [
                      'title' => $section->label(),
                      'number' => $section->get('field_course_number')->value,
                      'credits' => $section->get('field_credit_hours')->value,
                      'prerequisite' => $section->get('field_prerequisite')->value,
                    ];
                  }
                }
              }
            }
          }
        }
      }
    }

    // Return unique courses as a numerically indexed array
    return array_values($courses);
  }

  public static function sort_classes_by_prerequisite(array $classes) {
    // These are the classes that have been sorted already
    $classes_taken = [];

    for ($i = 0, $n = count($classes); $i < $n; $i++) {
      if ($classes[$i]['prerequisite'] == 'N/A') {
        // No prerequisite, can take this class now
        $classes_taken[] = $classes[$i];
        // Remove from original list
        array_splice($classes, $i, 1);
        // Adjust counters
        $i--;
        $n--;
      }
    }

    // At this point, we have added all classes without prerequisites
    // Now we loop until all classes are sorted
    $made_changes = true;
    while (!empty($classes) && $made_changes) {
      foreach ($classes as $id => $course) {
        // Check if prerequisites are met
        if (check_prerequisites($course['prerequisite'], $classes_taken)) {
          // Prerequisites met, can take this class now
          $classes_taken[] = $course;
          // Remove from original list
          unset($classes[$id]);
          // Mark that we made changes this pass
          $made_changes = true;
        }
      }
    }

    // If the loop finished but there are still classes left, it means there are impossible prerequisites
    if (!empty($classes)) {
      // Just append them at the end for now
      foreach ($classes as $course) {
        $classes_taken[] = $course;
      }
    
      return array_values($classes_taken);
    }
  }

  /**
 * Checks if a user has met the prerequisites based on a complex string.
 *
 * @param string $prereq_string 
 * The raw string (e.g., "(CS 1010 ... and CS 1020 ...)")
 * @param array $taken_classes 
 * An array of strings of classes taken (e.g., ['CS 1010', 'ENG 1100'])
 * @return bool
 * True if requirements are met, False otherwise.
 */
function check_prerequisites($prereq_string, array $taken_classes) {
    // No prerequisites
    if (empty(trim($prereq_string))) {
        return true;
    }

    // Normalize the taken classes array to uppercase/trimmed to ensure matching works
    // keys are not needed, just values.
    $taken_classes = array_map(function($c) {
        return strtoupper(trim($c));
    }, $taken_classes);

    // Pre-formatting: Convert logic words to PHP operators
    // We use \b (word boundaries) to ensure we don't replace parts of words
    $logic_string = preg_replace('/\band\b/i', '&&', $prereq_string);
    $logic_string = preg_replace('/\bor\b/i', '||', $logic_string);

    // Find Courses and replace them with 1 (True) or 0 (False)
    // Regex Pattern explanation:
    // [A-Z]{2,5}  -> Matches 2 to 5 uppercase letters (e.g., "CS", "EGR")
    // \s+         -> Matches one or more spaces
    // \d{3,5}     -> Matches 3 to 5 digits (e.g., "3100")
    $evaluated_string = preg_replace_callback(
        '/([A-Z]{2,5}\s+\d{3,5})/', 
        function($matches) use ($taken_classes) {
            $course_found = trim($matches[1]);
            
            // Check if this specific course exists in our taken array
            if (in_array($course_found, $taken_classes)) {
                return '1'; // True
            } else {
                return '0'; // False
            }
        }, 
        strtoupper($logic_string) // Pass uppercase string to match our uppercase array
    );

    // Remove EVERYTHING that is not logic or math.
    // This strips out "Undergraduate level", "Minimum Grade of C", etc.
    // We only keep: 1, 0, &, |, (, ), and spaces.
    $final_math = preg_replace('/[^01&|() ]/', '', $evaluated_string);

    // Safe Evaluation
    // Example final string: "(1 && (0 || 1))"
    try {
        return (bool) eval("return ($final_math);");
    } catch (\Throwable $t) {
        // If the string was malformed and caused a parse error
        error_log('Prerequisite Parse Error: ' . $t->getMessage());
        return false;
    }
  }
}
