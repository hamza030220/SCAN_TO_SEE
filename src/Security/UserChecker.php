<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    /**
     * Called before password verification — blocks login for inactive accounts.
     */
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isActive()) {
            throw new CustomUserMessageAuthenticationException(
                'Your account has been deactivated. Please contact support.'
            );
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        // nothing needed post-auth
    }
}
