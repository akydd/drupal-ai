<?php

namespace Drupal\service_intake\Controller;

use Drupal\webform\Entity\WebformSubmission;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for displaying intake result pages for webform submissions.
 */
class IntakeResultController extends ControllerBase {

  /**
   * Displays the intake result for a given submission.
   *
   * @param int $sid
   *   The webform submission ID.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the submission does not exist.
   */
  public function result($sid) {
    $submission = WebFormSubmission::load($sid);

    if ($submission === NULL) {
      throw new NotFoundHttpException();
    }

    $ministry = $submission->getElementData('ministry');
    $next_steps = $submission->getElementData('next_steps');
    $confidence = $submission->getElementData('confidence');
    $flagged = $submission->getElementData('flagged');

    return [
      '#theme' => 'intake_result',
      '#ministry' => $ministry,
      '#next_steps' => $next_steps,
      '#confidence' => $confidence,
      '#flagged' => $flagged,
    ];
  }

}
