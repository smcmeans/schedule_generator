<?php

namespace Drupal\schedule_generator\Service;

use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

class StudentProfileManager {

    protected $currentUser;
    protected $entityTypeManager;
    
    // Property to hold the cached node
    protected $loadedProfile = NULL;
    // Add a flag to know if we already checked (in case they don't have a profile)
    protected $profileChecked = FALSE;

    public function __construct(AccountProxyInterface $currentUser, EntityTypeManagerInterface $entityTypeManager) {
        $this->currentUser = $currentUser;
        $this->entityTypeManager = $entityTypeManager;
    }

    /**
    * Returns the Student Profile Node for the current user.
    */
    public function getStudentProfileNode() {
        // TEMP TEST - RETURN NULL
        return NULL;

        // If we already looked this up, return the saved result immediately.
        if ($this->profileChecked) {
            return $this->loadedProfile;
        }

        $uid = $this->currentUser->id();
        
        // Mark that we are checking now, so we don't run this again even if result is null
        $this->profileChecked = TRUE;

        if ($uid == 0) {
            return NULL;
        }

        // Database query to find the student profile node for this user
        $query = $this->entityTypeManager->getStorage('node')->getQuery();
        $nids = $query
            ->condition('type', 'student_profile')
            ->condition('field_student_account', $uid)
            ->range(0, 1)
            ->accessCheck(TRUE)
            ->execute();

        if (empty($nids)) {
            // Save NULL so we don't query again
            $this->loadedProfile = NULL;
            return NULL;
        }

        $nid = reset($nids);
        
        // Save the loaded node into the property
        $this->loadedProfile = $this->entityTypeManager->getStorage('node')->load($nid);
        
        return $this->loadedProfile;
    }

}