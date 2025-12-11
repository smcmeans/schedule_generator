<?php

namespace Drupal\schedule_generator;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use \Drupal\node\Entity\Node;

class ScheduleGenerator
{

  protected $entityTypeManager;
  protected $studentProfileManager;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, StudentProfileManager $studentProfileManager)
  {
    $this->entityTypeManager = $entityTypeManager;
    $this->studentProfileManager = $studentProfileManager;
  }

  /**
   * Gets the desired credit hours from a student profile
   * 
   * @param NodeInterface $studentProfile
   * The student profile to search
   * 
   * @return int
   * The desired credit hours or 12 if not found
   */
  public static function get_desired_credit_hours(NodeInterface $studentProfile)
  {
    $credit_hours = $studentProfile->get('field_desired_credit_hours')->value;
    return is_numeric($credit_hours) ? (int) $credit_hours : 12; // Default to 12 if not set
  }

  /**
   * Gets all of the classes from the student profile
   * 
   * @param NodeInterface $studentProfile
   * The node that will be searched
   * 
   * @return array
   * Array containing the required classes and all of the classes in the program
   */
  public static function get_all_classes(NodeInterface $studentProfile)
  {
    $return_array = [];

    $selected_courses = [];
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
            $i = 0;
            // Get courses from each option
            if ($option->hasField('field_option_courses')) {
              $course_entities = $option->get('field_option_courses')->referencedEntities();
              foreach ($course_entities as $course) {
                $formatted_linked_sections = [];

                if (!$course->get('field_linked_sections')->isEmpty()) {
                  $linked_entities = $course->get('field_linked_sections')->referencedEntities();

                  foreach ($linked_entities as $section) {
                    $formatted_linked_sections[] = [
                      'id' => $section->id(),
                      'title' => $section->label(),
                      'number' => $section->get('field_course_number')->value,
                      'credits' => $section->get('field_credit_hours')->value,
                      'prerequisite' => $section->get('field_prerequisite')->value ?? 'N/A',
                    ];
                  }
                }


                $courses[$course->id()] = [
                  'id' => $course->id(),
                  'title' => $course->label(),
                  'number' => $course->get('field_course_number')->value,
                  'credits' => $course->get('field_credit_hours')->value,
                  'prerequisite' => $course->get('field_prerequisite')->value,
                  'linked_sections' => $formatted_linked_sections,
                ];
              }
              // Add first class to selected courses array
              if ($i == 0) {
                $selected_courses[$course->id()] = [
                  'id' => $course->id(),
                  'title' => $course->label(),
                  'number' => $course->get('field_course_number')->value,
                  'credits' => $course->get('field_credit_hours')->value,
                  'prerequisite' => $course->get('field_prerequisite')->value,
                  'linked_sections' => $formatted_linked_sections,
                ];
              }
              $i++;
            }
          }
        }
      }
    }

    // Return unique courses as a numerically indexed array
    $return_array[] = array_values($selected_courses);
    $return_array[] = array_values($courses);
    return array_values($return_array);
  }

  /**
   * Sorts the passed array by the ['number'] field
   * 
   * @param array $classes
   * The array to sort
   * 
   * @return array
   * Sorted list
   */
  public static function sort_courses_by_number(array $classes)
  {
    usort($classes, function ($a, $b) {
      return strcmp(preg_replace('[\D]', '', $a['number']), preg_replace('[\D]', '', $b['number']));
    });
    return $classes;
  }

  /**
   * Sorts the array by prerequisite chain, also splits the array into semesters
   * Adds a coop semester if coop is true
   * 
   * @param array $all_classes
   * Array from get_all_classes function
   * @param int $desired_credits
   * The amount of credits that the function will try to generate each semester
   * @param bool $coop
   * If the student wants a coop
   * 
   * @return array
   * 2D array with the first array holding the semester number and the second being the classes for each semester
   */
  public static function sort_classes_by_prerequisite(array $all_classes, int $desired_credits, bool $coop) {
    // Setup
    $classes = self::sort_courses_by_number($all_classes[0]);
    $classes_taken = [];
    $current_semester = 0;
    $buffer = [];

    // Process classes with no prerequisites immediately
    self::process_no_prereq_classes($classes, $buffer, $classes_taken, $current_semester, $desired_credits, $coop);

    // Loop through remaining classes and resolve prerequisites
    self::process_complex_classes($classes, $buffer, $classes_taken, $current_semester, $desired_credits, $coop);

    // Force add any unresolvable classes (Error fallback)
    if (!empty($classes)) {
      self::force_add_remaining_classes($classes, $buffer, $classes_taken, $current_semester, $desired_credits, $coop);
    }

    return array_values($classes_taken);
  }

  /**
   * Handle classes with 'N/A' prerequisites.
   */
  private static function process_no_prereq_classes(array &$classes, array &$buffer, array &$classes_taken, int &$current_semester, int $desired_credits, bool $coop) {
    foreach ($classes as $id => $course) {
      if ($course['prerequisite'] == 'N/A') {
        self::add_course_to_buffer($course, $buffer);
        unset($classes[$id]);

        // Check if we need to close the semester
        self::attempt_semester_close($buffer, $classes_taken, $current_semester, $desired_credits, $coop);
      }
    }
    
    // If buffer isn't empty after Phase 1 but no semester closed, force close it to start fresh for Phase 2
    // (This matches your original logic: "Check if buffer has been cleared yet")
    if ($current_semester == 0 && !empty($buffer)) {
        self::close_semester($buffer, $classes_taken, $current_semester, $coop);
    }
  }

  /**
   * Contentiously tries to fit classes where prereqs are met.
   */
  private static function process_complex_classes(array &$classes, array &$buffer, array &$classes_taken, int &$current_semester, int $desired_credits, bool $coop) {
    $made_changes = true;

    while (!empty($classes)) {
      // Inner loop: Keep trying as long as we make progress
      while ($made_changes) {
        $made_changes = false;

        foreach ($classes as $id => $course) {
          // Check Credit Limit
          if (self::get_total_credits($buffer) + $course['credits'] > $desired_credits) {
            continue; 
          }

          // Check Prerequisites
          $all_taken_codes = self::get_all_classes_number($classes_taken);
          if (self::check_prerequisites($course['prerequisite'], $all_taken_codes)) {
            
            self::add_course_to_buffer($course, $buffer);
            unset($classes[$id]);
            $made_changes = true;

            // Check if we need to close the semester
            self::attempt_semester_close($buffer, $classes_taken, $current_semester, $desired_credits, $coop);
          }
        }
      }

      // If loop finished with no changes, but buffer has items, close the semester to free up slots
      if (!empty($buffer)) {
        self::close_semester($buffer, $classes_taken, $current_semester, $coop);
        $made_changes = true; // Force another pass since a new semester started
      } else {
        // Impossible prerequisites found (deadlock)
        break;
      }
    }
  }

  /**
   * Fallback for unresolvable prerequisites.
   */
  private static function force_add_remaining_classes(array $classes, array &$buffer, array &$classes_taken, int &$current_semester, int $desired_credits, bool $coop) {
    foreach ($classes as $course) {
      error_log('Unresolvable Prerequisite: ' . $course['number']);
      self::add_course_to_buffer($course, $buffer);
      self::attempt_semester_close($buffer, $classes_taken, $current_semester, $desired_credits, $coop);
    }
  }

  /**
   * Helper: Adds a course and its linked sections to the buffer.
   */
  private static function add_course_to_buffer(array $course, array &$buffer) {
    $buffer[] = $course;
    if (!empty($course['linked_sections'])) {
      foreach ($course['linked_sections'] as $linked_course) {
        $buffer[] = $linked_course;
      }
    }
  }

  /**
   * Helper: Checks if credits are full. If so, closes the semester.
   */
  private static function attempt_semester_close(array &$buffer, array &$classes_taken, int &$current_semester, int $desired_credits, bool $coop) {
    if ($desired_credits - self::get_total_credits($buffer) <= 1) {
      self::close_semester($buffer, $classes_taken, $current_semester, $coop);
    }
  }

  /**
   * Helper: Moves buffer to taken, increments semester, handles Co-op.
   */
  private static function close_semester(array &$buffer, array &$classes_taken, int &$current_semester, bool $coop) {
    foreach ($buffer as $c) {
      $classes_taken[$current_semester][] = $c;
    }
    
    $current_semester++;
    $buffer = []; // Clear buffer

    // Handle Co-op logic
    if ($coop && $current_semester == 5) {
      self::add_coop($classes_taken);
      $current_semester++;
    }
  }

  /**
   * Gets all of the class numbers from a 2D array
   * 
   * @param array $schedules
   * The array that is being searched
   * 
   * @return array
   * Array containing all of the course numbers
   */
  private static function get_all_classes_number(array $schedules)
  {
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

  /**
   * Gets the total credits of an array with the ['credits'] column
   * 
   * @param array $courses
   * The array that will be totaled
   * 
   * @return int
   * The total credits from $courses
   */
  private static function get_total_credits(array $courses)
  {
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
   * 
   * @return bool
   * True if requirements are met, False otherwise.
   */
  public static function check_prerequisites($prereq_string, array $taken_classes)
  {
    // No prerequisites
    if (empty(trim($prereq_string)) || $prereq_string === 'N/A') {
      return true;
    }

    // Remove informational notes like "(MTH 2310 can be taken concurrently)"
    // This removes any set of parentheses containing the word "concurrently"
    $logic_string = preg_replace('/\([^()]*concurrently[^()]*\)/i', '', $prereq_string);

    // Remove high school classes
    $logic_string = preg_replace('/High School.*\)/i', '1)', $logic_string);

    // Normalize the taken classes array to uppercase/trimmed to ensure matching works
    // keys are not needed, just values.
    $taken_classes = array_map(function ($c) {
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
      function ($matches) use ($taken_classes) {
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

  // Unused
  private static function clear_buffer(array $buffer, array $classes_taken, int $current_semester, int $desired_credits)
  {
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

  /**
   * Adds an empty coop semester to the end of passed array
   * 
   * @param array &$courses
   * The array that the coop will be added to
   */
  private static function add_coop(array &$courses)
  {
    $coop_id = 399;

    $coop = Node::load($coop_id);
    if ($coop) {
      $coop_data = [
        'id' => $coop_id,
        'title' => $coop->label(),
        'number' => $coop->get('field_course_number')->value,
        'credits' => $coop->get('field_credit_hours')->value,
        'prerequisite' => $coop->get('field_prerequisite')->value,
      ];

      $courses[] = [$coop_data];
    }
  }

  /**
   * Get upcoming semester taxonomy terms.
   * 
   * @param int $limit
   * The amount of semesters to get, max is 32
   * 
   * @return array
   * A list of the upcoming semesters
   */
  public static function get_upcoming_semesters($limit = 32)
  {
    // Load terms from the 'semesters' vocabulary
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $query = $storage->getQuery()
      ->condition('vid', 'semesters')
      ->sort('weight', 'ASC')
      ->accessCheck(TRUE);

    $tids = $query->execute();
    $terms = $storage->loadMultiple($tids);

    return array_values($terms);
  }

  /**
   * Returns the id of the desired graduation semester of the passed node
   */
  private static function get_desired_graduation_semester(NodeInterface $student_node) {
    if ($student_node->get('field_graduation_semester')->isEmpty()) {
        return NULL;
    }

    return $student_node->get('field_graduation_semester')->target_id;
  }

  /**
   * Saves the array to the passed node
   * 
   * @param NodeInterface $student_node
   * The node that the array will be saved to
   * @param array $schedule
   * The 2D array containing the schedule
   * 
   * @return bool
   * If we passed the desired graduation semested
   */
  public static function save_schedule_to_node(NodeInterface $student_node, array $schedule)
  {

    // Get the Semester Taxonomy Terms (to map index 0 -> Fall 2025)
    $semester_terms = self::get_upcoming_semesters();

    // Clear the existing Academic Plan to avoid duplicates
    $student_node->set('field_academic_plan', []);

    // Variables to check if we passed the desired graduation date
    $desired_graduation_semester = self::get_desired_graduation_semester($student_node);
    $passed = false;
    $return_value = true;

    // Loop through the Generated Schedule
    // $classes is the array of course data
    foreach ($schedule as $semester_index => $classes) {

      // Check if term exists in semester taxonomy
      if (!isset($semester_terms[$semester_index])) {
        \Drupal::logger('schedule_generator')->warning('Not enough semester terms created in the system to cover the generated schedule.');
        continue;
      }

      $semester_term_id = $semester_terms[$semester_index]->id();

      // Create the Paragraph Entity
      $paragraph = Paragraph::create([
        'type' => 'semester_schedule',
        'field_semester' => $semester_term_id,
      ]);

      // Add Classes to the Paragraph
      $class_ids = [];
      foreach ($classes as $class_data) {
        if (isset($class_data['id'])) {
          $class_ids[] = $class_data['id'];
        }
      }

      // field_planned_classes is the Entity Reference field on the Paragraph
      $paragraph->set('field_planned_classes', $class_ids);

      // Save the Paragraph
      $paragraph->isNew();
      $paragraph->save();

      // Attach Paragraph to Student Node
      $student_node->get('field_academic_plan')->appendItem($paragraph);

      // Check if passed
      if ($passed) {
        $return_value = false;
      }
      
      // Check if current semester is desired semester
      if ($semester_term_id == $desired_graduation_semester) {
        $passed = true;
      }
    }

    // Save the Student Node
    $student_node->save();

    return $return_value;
  }
}
