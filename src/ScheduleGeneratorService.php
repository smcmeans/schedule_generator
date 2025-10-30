// Inside your ScheduleGeneratorService.php

public function generateSuggestions(Node $studentProfile) {
    // 1. Get student's data
    $desired_credits = $studentProfile->get('field_desired_credit_hours')->value;
    $completed_courses = $studentProfile->get('field_completed_courses')->referencedEntities();
    $majors = $studentProfile->get('field_selected_majors')->referencedEntities();
    $minors = $studentProfile->get('field_selected_minors')->referencedEntities();

    $all_programs = array_merge($majors, $minors);
    $suggested_courses = [];
    $suggested_credits = 0;

    // Create a list of completed course NIDs for easy checking
    $completed_course_nids = [];
    foreach ($completed_courses as $course) {
        $completed_course_nids[] = $course->id();
    }

    // 2. Loop through all programs (Majors first)
    foreach ($all_programs as $program) {
        // 3. Get REQUIRED courses first
        $required_courses = $program->get('field_required_courses')->referencedEntities();

        foreach ($required_courses as $course) {
            // Check if already complete
            if (in_array($course->id(), $completed_course_nids)) {
                continue; // Skip this course
            }

            // Check prerequisites
            if (!$this->checkPrerequisites($course, $completed_course_nids)) {
                continue; // Skip, prereqs not met
            }

            // Add to suggestions if we're under credit limit
            $course_credits = $course->get('field_credit_hours')->value;
            if (($suggested_credits + $course_credits) <= $desired_credits) {
                $suggested_courses[$course->id()] = $course; // Use ID as key to prevent duplicates
                $suggested_credits += $course_credits;
            }
        }
    }

    // 4. If we still need credits, add ELECTIVES
    if ($suggested_credits < $desired_credits) {
        foreach ($all_programs as $program) {
            $elective_courses = $program->get('field_elective_courses')->referencedEntities();
            
            foreach ($elective_courses as $course) {
                if (in_array($course->id(), $completed_course_nids) || isset($suggested_courses[$course->id()])) {
                    continue; // Skip, already completed or already in list
                }
                
                if (!$this->checkPrerequisites($course, $completed_course_nids)) {
                    continue; // Skip, prereqs not met
                }

                // Add to suggestions if we're under credit limit
                $course_credits = $course->get('field_credit_hours')->value;
                if (($suggested_credits + $course_credits) <= $desired_credits) {
                    $suggested_courses[$course->id()] = $course;
                    $suggested_credits += $course_credits;
                }

                // Stop if we've hit the target
                if ($suggested_credits >= $desired_credits) {
                    break 2; // Breaks out of both loops
                }
            }
        }
    }

    return $suggested_courses;
}

// Helper function in the same service
private function checkPrerequisites(Node $course, array $completed_course_nids) {
    $prereqs = $course->get('field_prerequisites')->referencedEntities();
    if (empty($prereqs)) {
        return true; // No prereqs
    }

    foreach ($prereqs as $prereq) {
        if (!in_array($prereq->id(), $completed_course_nids)) {
            return false; // Missing a prerequisite
        }
    }
    return true; // All prereqs met
}
