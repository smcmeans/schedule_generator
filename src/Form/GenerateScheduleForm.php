<?php

namespace Drupal\schedule_generator\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\schedule_generator\ScheduleGenerator;

class GenerateScheduleForm extends FormBase
{

  /**
   * Returns the id of the form
   */
  public function getFormId()
  {
    return 'schedule_generator_generate_form';
  }

  /**
   * Builds a button that says Generate Schedule
   * 
   * @param array $form
   * The button form
   * @param FormStateInterface $form_state
   * The form state
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate Schedule'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Calls the functions to generate and save the schedule
   * 
   * @param array &$form
   * The form to submit
   * @param FormStateInterface $form_state
   * The form state used to refresh the page
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    try {
      $student = \Drupal::service('schedule_generator.student_manager')->getStudentProfileNode();

      if ($student) {
        $coop = false;
        if ($student->hasField('field_coop') && !$student->get('field_coop')->isEmpty()) {
          $coop = $student->get('field_coop')->value;
        }
        $all_classes = ScheduleGenerator::get_all_classes($student);

        $sorted = ScheduleGenerator::sort_classes_by_prerequisite($all_classes, ScheduleGenerator::get_desired_credit_hours($student), $coop);

        $graduation_passed = ScheduleGenerator::save_schedule_to_node($student, $sorted);

        if ($graduation_passed) {
          $this->messenger()->addStatus($this->t('Schedule generated successfully!'));
        } else {
          $this->messenger()->addError($this->t('Generated schedule goes past desired graduation date!'));
        }
      } else {
        $this->messenger()->addError($this->t('Could not find a student profile for the current user.'));
      }
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('An error occurred: @error', ['@error' => $e->getMessage()]));
    }
    // Refresh page
    $form_state->setRedirect('<current>');
  }
}
