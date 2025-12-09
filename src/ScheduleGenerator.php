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

    return 15;

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
                  // 'linked_sections' => $course->get('field_linked_sections')->referencedEntities(),
                ];
                print_r($courses);

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

    foreach ($classes as $id => $course) {
      if ($course['prerequisite'] == 'N/A') {
        // No prereqs, can be added to buffer
        $buffer[] = $course;
        if (!empty($course['linked_sections'])) {
          foreach ($course['linked_sections'] as $linked_course) {
            $linked_course_data = [
              'title' => $linked_course->label(),
              'number' => $linked_course->get('field_course_number')->value,
              'credits' => $linked_course->get('field_credit_hours')->value,
              'prerequisite' => $linked_course->get('field_prerequisite')->value
            ];
            print_r($linked_course_data);
            $buffer[] = $linked_course_data;
          }
        }
        // Remove from class list
        unset($classes[$id]);
        if ($desired_credits - self::get_total_credits($buffer) <= 1) {
          foreach ($buffer as $c) {
            $classes_taken[$current_semester][] = $c;
          }
          $current_semester++;
          // Clear buffer for next semester
          $buffer = [];
        }
      }
    }
    if (!empty($buffer)) {
      foreach ($buffer as $c) {
                $classes_taken[$current_semester][] = $c;
              }
              $current_semester++;
              // Clear buffer for next semester
              $buffer = [];
    }

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
                print_r($linked_course_data);
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
        // Set made changes back to true
        $made_changes = true;
        echo "Cleared buffer";
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
        error_log('Unresolvable Prerequisite for Course: ' . $course['number'] . ' | Prerequisite: ' . $course['prerequisite']);
      }  
    }
    
    return array_values($classes_taken);
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
      }
    }
    return $all_courses_number;
  }

  private static function get_total_credits(array $courses) {
    $total = 0;
    foreach ($courses as $course) {
      $total += (int) $course['credits'];
    }
    echo $total;
    echo " ";
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
    if (empty($prereq_string) || $prereq_string === 'N/A') {
      return true;
    }

    // Convert ' and ' to ' && ', ' or ' to ' || ' (case insensitive)
    $logic_string = str_ireplace([' and ', ' or '], [' && ', ' || '], $prereq_string);

    // Remove phrases that don't help identify the course.
    $noise_phrases = [
      'Undergraduate level',
      'Minimum Grade of D',
      'Minimum Grade of C',
      'Minimum Grade of P',
      'can be taken concurrently',
      '(', // Temporarily remove parens inside specific course notes if needed, 
           // but usually better to rely on logic structure.
    ];
    
    // We don't remove '(', ')' generally, only specific noise. 
    // Actually, simple string replacement for noise works best
    $clean_string = $logic_string;
    foreach ($noise_phrases as $phrase) {
        $clean_string = str_ireplace($phrase, '', $clean_string);
    }

    // Extract Tokens (The requirements) using Regex
    // Look for logic operators to split the string, preserving delimiters.
    // The pattern splits by (, ), &&, ||
    $tokens = preg_split('/(\(|\)|&&|\|\|)/', $clean_string, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

    $eval_string = "";

    foreach ($tokens as $token) {
      $trim_token = trim($token);

      // If it's a logic operator, keep it as is
      if (in_array($trim_token, ['(', ')', '&&', '||'])) {
        $eval_string .= " $trim_token ";
        continue;
      }

      // If it's whitespace only, skip
      if (empty($trim_token)) {
        continue;
      }

      // 4. Check the Token
      // $trim_token is now something like "CHM 1010" or "WSU Math Placement Level 24"
      $is_taken = self::is_requirement_met($trim_token, $taken_classes);
      
      // Append 1 (true) or 0 (false) to our evaluation string
      $eval_string .= $is_taken ? " 1 " : " 0 ";
    }

    // 5. Safe Evaluation
    // $eval_string now looks like "( 1 || 0 ) && ( 1 )"
    // valid boolean math.
    try {
        return eval("return ($eval_string);");
    } catch (\Throwable $e) {
        error_log("Prereq Parse Error: " . $e->getMessage() . " on string: " . $eval_string);
        return false;
    }
  }

  private static function is_requirement_met($requirement_name, $classes_taken_numbers) {
    // Clean up the requirement name
    $req = trim($requirement_name);

    if (in_array($req, $classes_taken_numbers)) {
        return true;
    }

    if (strpos($req, 'Placement Level') !== false) {
        // TODO: Implement actual check: $student->getPlacementLevel() >= 24
        return true; 
    }

    return false;
  }

  private static function clear_buffer(array $buffer, array $classes_taken, int $current_semester, int $desired_credits) {
    if ($desired_credits - self::get_total_credits($buffer) <= 1) {
      foreach ($buffer as $c) {
        $classes_taken[$current_semester][] = $c;
        }
        $current_semester++;
        // Clear buffer for next semester
        $buffer = [];
    }
    return $current_semester;
  }
}
