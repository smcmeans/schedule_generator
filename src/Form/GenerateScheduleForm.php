<?php

namespace Drupal\schedule_generator\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class GenerateScheduleForm extends FormBase {

  public function getFormId() {
    return 'schedule_generator_generate_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['markup'] = [
      '#markup' => 'This is a placeholder form.',
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Load Student
    $student = \Drupal::service('schedule_generator.student_manager')->getStudentProfileNode();

    $all_classes = ScheduleGenerator::get_all_classes($student);
    $schedule = ScheduleGenerator::sort_classes_by_prerequisite($all_classes);

    ScheduleGenerator::save_schedule_to_node($student, $schedule);
  }
}