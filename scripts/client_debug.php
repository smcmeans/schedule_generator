<?php

/**
 * Standalone Test Script for Schedule Logic
 * Run this in VS Code terminal: php debug_prereq.php
 */

class ScheduleGenerator {
public static function sort_courses_by_number(array $classes) {
    usort($classes, function($a, $b) {
      return strcmp(preg_replace('[\D]', '', $a['number']), preg_replace('[\D]', '', $b['number']));
    });
    return $classes;
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

private static function clear_buffer(array $buffer, array $classes_taken, int $current_semester, int $desired_credits) {
  if ($desired_credits - self::get_total_credits($buffer) <= 1) {
    foreach ($buffer as $c) {
      $classes_taken[$current_semester][] = $c;
      }
      $current_semester++;
      // Clear buffer for next semester
      $buffer = [];
  }
}

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
        self::clear_buffer($buffer, $classes_taken, $current_semester, $desired_credits);
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
                $buffer[] = $linked_course_data;
              }
            }
            // If we have reached desired credits (mostly), finalize this semester
            self::clear_buffer($buffer, $classes_taken, $current_semester, $desired_credits);
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

private static function get_total_credits(array $courses) {
    $total = 0;
    foreach ($courses as $course) {
      $total += (int) $course['credits'];
    }
    return $total;
  }
}

// ==========================================
// TEST DATA
// ==========================================

// 1. Define the classes the student has taken
$student_transcript = [
    [
        'number' => 'MTH 2300',
        'credits' => 4,
        'prerequisite' => 'WSU Math Placement Level 50 or Undergraduate level MTH 1350 Minimum Grade of B or Undergraduate level MTH 0300 Minimum Grade of P (MTH 0300 can be taken concurrently)',
    ],
    [
        'number' => 'MTH 2310',
        'credits' => 4,
        'prerequisite' => 'Undergraduate level MTH 2300 Minimum Grade of D',
    ],
    [
        'number' => 'MTH 2530',
        'credits' => 3,
        'prerequisite' => 'Undergraduate level MTH 2300 Minimum Grade of D',
    ],
    [
        'number' => 'MTH 2320',
        'credits' => 4,
        'prerequisite' => 'Undergraduate level MTH 2310 Minimum Grade of D',
    ],
    [
        'number' => 'MTH 2330',
        'credits' => 3,
        'prerequisite' => 'Undergraduate level MTH 2310 Minimum Grade of D',
    ],
    [
        'number' => 'MTH 2350',
        'credits' => 4,
        'prerequisite' => 'Undergraduate level MTH 2310 Minimum Grade of D',
    ],
    [
        'number' => 'MTH 2570',
        'credits' => 3,
        'prerequisite' => 'Undergraduate level MTH 1280 Minimum Grade of D or WSU Math Placement Level 40',
    ],
    [
        'number' => 'MTH 2800',
        'credits' => 4,
        'prerequisite' => 'Undergraduate level MTH 2310 Minimum Grade of D or (Undergraduate level MTH 2300 Minimum Grade of C and Undergraduate level MTH 2310 Minimum Grade of D (MTH 2310 can be taken concurrently))',
    ],
    [
        'number' => 'MTH 1350',
        'credits' => 3,
        'prerequisite' => 'Undergraduate level MTH 1280 Minimum Grade of D or WSU Math Placement Level 40',
    ],
    [
        'number' => 'MTH 1280',
        'credits' => 3,
        'prerequisite' => 'N/A',
    ],
    [
      'number' => 'IMP 9999',
      'credits' => 10,
      'prerequisite' => 'IMP 9999',
    ]
];


print_r(ScheduleGenerator::sort_classes_by_prerequisite($student_transcript, 10));