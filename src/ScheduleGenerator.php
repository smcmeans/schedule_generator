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

  // Untested
  public static function get_desired_credit_hours(NodeInterface $studentProfile) {
    $credit_hours = $studentProfile->get('field_desired_credit_hours')->value;
    return is_numeric($credit_hours) ? (int) $credit_hours : 12; // Default to 12 if not set
  }

  // Tested works
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
                  'linked_sections' => $course->get('field_linked_sections')->referencedEntities(),
                ];

                // // Add labs/recitations if they exist
                // // Might want to move this to the final schedule generation command to ensure linked sections are not split
                // // across semesters
                // if ($course->hasField('field_linked_sections') && !$course->get('field_linked_sections')->isEmpty()) {
                //   $linked_sections = $course->get('field_linked_sections')->referencedEntities();
                //   foreach ($linked_sections as $section) {
                //     $courses[$section->id()] = [
                //       'title' => $section->label(),
                //       'number' => $section->get('field_course_number')->value,
                //       'credits' => $section->get('field_credit_hours')->value,
                //       'prerequisite' => $section->get('field_prerequisite')->value,
                //     ];
                //   }
                // }
              }
            }
          }
        }
      }
    }

    // Return unique courses as a numerically indexed array
    return array_values($courses);
  }

  // Untested
  public static function sort_courses_by_number(array $classes) {
    usort($classes, function($a, $b) {
      return strcmp(preg_replace('[\D]', '', $a['number']), preg_replace('[\D]', '', $b['number']));
    });
    return $classes;
  }

  // Tested works
  public static function sort_classes_by_prerequisite(array $classes, int $desired_credits) {
    // These are the classes that have been sorted already
    $classes_taken = [];
    $current_semester = 0;

    // Buffer to hold classes for current semester
    $buffer = [];

    // Sort classes to better match levels (1000s, 2000s, etc.)
    $classes = self::sort_courses_by_number($classes);

    // for ($i = 0, $n = count($classes); $i < $n; $i++) {
    //   if ($classes[$i]['prerequisite'] == 'N/A') {
    //     // No prerequisite, can take this class now
    //     $classes_taken[] = $classes[$i];
    //     // Remove from original list
    //     array_splice($classes, $i, 1);
    //     // Adjust counters
    //     $i--;
    //     $n--;
    //   }
    // }

    // At this point, we have added all classes without prerequisites
    // Now we loop until all classes are sorted
    $made_changes = true;
    while (!empty($classes)) {
      while ($made_changes) {
        // Reset change flag
        $made_changes = false;
        foreach ($classes as $id => $course) {
          if (self::get_total_credits($buffer) + $course['credits'] > $desired_credits) {
            // Not enough room in this semester, move to next course
          }
          // Check if prerequisites are met
          elseif (self::check_prerequisites($course['prerequisite'], self::get_all_classes_number($classes_taken))) {
            // Prerequisites met, can take this class now
            $buffer[] = $course;
            // Remove from original list
            unset($classes[$id]);
            // Mark that we made changes this pass
            $made_changes = true;
            // Add linked courses if they exist
            if (!empty($course['linked_sections'])) {
              foreach ($course['linked_sections'] as $linked_course) {
                $linked_course_data = [
                  'title' => $linked_course->label(),
                  'number' => $linked_course->get('field_course_number')->value,
                  'credits' => $linked_course->get('field_credit_hours')->value,
                  'prerequisite' => $linked_course->get('field_prerequisite')->value
                ];
                $buffer[] = $linked_course_data;
              }
            }

            // If we have reached desired credits (mostly), finalize this semester
            if ($desired_credits - self::get_total_credits($buffer) <= 1) {
              // Add buffer to classes taken
              foreach ($buffer as $c) {
                $classes_taken[$current_semester][] = $c;
              }
              $current_semester++;
              // Clear buffer for next semester
              $buffer = [];
            }
          }
        }
      }
      // If we exit the inner loop without changes, finalize any remaining buffer
      if (!empty($buffer)) {
        // Add buffer to classes taken
        foreach ($buffer as $c) {
          $classes_taken[$current_semester][] = $c;
        }
        $current_semester++;
        // Clear buffer for next semester
        $buffer = [];
      } else {
        // TODO: Get starter classes, like basic MTH classes, to break impossible prerequisites
        break; // No more changes can be made, impossible prerequisites
      }
    }

    // If the loop finished but there are still classes left, it means there are impossible prerequisites
    if (!empty($classes)) {
      // Just append them at the end for now
      foreach ($classes as $course) {
        $classes_taken[] = $course;
        error_log('Unresolvable Prerequisite for Course: ' . $course['number'] . ' - ' . $course['title'] . ' | Prerequisite: ' . $course['prerequisite']);
      }
    
      return array_values($classes_taken);
    }
  }

  private static function get_all_classes_number(array $schedules) {
    $all_courses_number = [];
    // Schedules should be a 2D array, but if no data has been added yet, just return empty
    if (empty($schedules)) {
      return $all_courses_number;
    }
    foreach ($schedules as $semester_schedule) {
      foreach ($semester_schedule as $course) {
        $all_courses_number[] = $course['number'];
        // Test print
        echo $course['number'];
      }
    }
    return $all_courses_number;
  }

  private static function get_total_credits(array $courses) {
    $total = 0;
    foreach ($courses as $course) {
      $total += (int) $course['credits'];
    }
    return $total;
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
  public static function check_prerequisites($prereq_string, array $taken_classes) {
    // No prerequisites
    if (empty(trim($prereq_string)) || $prereq_string === 'N/A') {
        return true;
    }

    // Remove informational notes like "(MTH 2310 can be taken concurrently)"
    // This removes any set of parentheses containing the word "concurrently"
    $logic_string = preg_replace('/\([^()]*concurrently[^()]*\)/i', '', $prereq_string);

    // Normalize the taken classes array to uppercase/trimmed to ensure matching works
    // keys are not needed, just values.
    $taken_classes = array_map(function($c) {
        return strtoupper(trim($c));
    }, $taken_classes);

    // Pre-formatting: Convert logic words to PHP operators
    // We use \b (word boundaries) to ensure we don't replace parts of words
    $logic_string = preg_replace('/\band\b/i', '&&', $logic_string);
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

    // Might be unnecessary, but remove any empty parentheses that could cause eval errors
    while (strpos($final_math, '()') !== false) {
        $final_math = str_replace('()', '', $final_math);
    }

    // Safe Evaluation
    // Example final string: "(1 && (0 || 1))"
    try {
        return (bool) eval("return ($final_math);");
    } catch (\Throwable $t) {
        // If the string was malformed and caused a parse error
        error_log('Prerequisite Parse Error: ' . $t->getMessage());
        error_log('Offending String: ' . $final_math);
        error_log('Original Prerequisite: ' . $prereq_string);
        return false;
    }
  }
}
