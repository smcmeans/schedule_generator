<?php

namespace Drupal\schedule_generator\Service;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

class StudentProfileManager {

    protected $currentUser;
    protected $entityTypeManager;

    // We inject dependencies here via the constructor
    public function __construct(AccountProxyInterface $currentUser, EntityTypeManagerInterface $entityTypeManager) {
        $this->currentUser = $currentUser;
        $this->entityTypeManager = $entityTypeManager;
    }

    /**
    * Returns the Student Profile Node for the current user.
    */
    public function getStudentProfileNode() {
        $uid = $this->currentUser->id();
        if ($uid == 0) {
            return NULL;
        }

        $query = $this->entityTypeManager->getStorage('node')->getQuery();
        $nids = $query
            ->condition('type', 'student_profile')
            ->condition('field_student_account', $uid)
            ->range(0, 1)
            ->accessCheck(TRUE) // Important in Drupal 10+ for security!
            ->execute();

        if (empty($nids)) {
            return NULL;
        }

        $nid = reset($nids);
        return $this->entityTypeManager->getStorage('node')->load($nid);
    }

}