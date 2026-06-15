<?php

namespace Drupal\service_intake\Plugin\WebformHandler;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\Core\Form\FormStateInterface;
use Drupal\service_intake\IntakeService;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a webform handler for intake classification.
 *
 * @WebformHandler(
 *   id = "intake_classifier",
 *   label = @Translation("Intake Classifier"),
 *   category = @Translation("AI"),
 *   description = @Translation("Classifies service requests using Claude."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class IntakeClassifierHandler extends WebformHandlerBase {

  /**
   * The intake service.
   *
   * @var \Drupal\service_intake\IntakeService
   */
  protected IntakeService $intakeService;

  /**
   * The AI provider service.
   *
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected AiProviderPluginManager $aiProvider;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['confidence_threshold' => 0.85];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['confidence_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Confidence threshold'),
      '#default_value' => $this->configuration['confidence_threshold'],
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.05,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['confidence_threshold'] = $form_state->getValue('confidence_threshold');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->intakeService = new IntakeService();

    $instance->aiProvider = $container->get('ai.provider');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $operation = 'insert'): void {
    $request = $webform_submission->getElementData('service_request');

    try {
      $config = $this->aiProvider->getDefaultProviderForOperationType('chat');
      if ($config === NULL) {
        throw new \RuntimeException('No AI provider configured for chat operations.');
      }

      $provider = $this->aiProvider->createInstance($config['provider_id']);
      $input = new ChatInput([
        new ChatMessage('system', IntakeService::SYSTEM_PROMPT),
        new ChatMessage('user', $request),
      ]);
      $raw = $provider->chat($input, $config['model_id'])->getNormalized()->getText();
    }
    catch (\Exception $e) {
      $this->getLogger('service_intake')->error('AI classification failed: @message', ['@message' => $e->getMessage()]);
    }

    $result = $this->intakeService->parseResponse($raw ?? '');
    $result = $this->intakeService->applyThreshold($result, $this->configuration['confidence_threshold']);

    $webform_submission->setElementData('ministry', $result['ministry'] ?? 'Unknown');
    $webform_submission->setElementData('next_steps', $result['next_steps'] ?? 'There was an error processing your request.');
    $webform_submission->setElementData('confidence', $result['confidence'] ?? 0.0);
    $webform_submission->setElementData('needs_review', $result['needs_review'] ?? TRUE);
    $webform_submission->resave();
  }

}
