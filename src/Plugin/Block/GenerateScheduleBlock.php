<?php

namespace Drupal\schedule_generator\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Generate Schedule' Block.
 *
 * @Block(
 * id = "schedule_generator_button_block",
 * admin_label = @Translation("Generate Schedule Button"),
 * category = @Translation("Custom")
 * )
 */
class GenerateScheduleBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected $formBuilder;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, FormBuilderInterface $form_builder) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->formBuilder = $form_builder;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('form_builder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Render the form
    return $this->formBuilder->getForm('Drupal\schedule_generator\Form\GenerateScheduleForm');
  }
}