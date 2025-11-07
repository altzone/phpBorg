<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Repository\UserRepository;
use PhpBorg\Exception\PhpBorgException;

/**
 * User management API controller
 */
class UserController extends BaseController
{
    private readonly UserRepository $userRepository;

    public function __construct(Application $app)
    {
        $this->userRepository = new UserRepository($app->getConnection());
    }

    /**
     * GET /api/users
     * List all users
     */
    public function list(): void
    {
        try {
            // Only ROLE_ADMIN can list users
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $users = $this->userRepository->findAll();

            $this->success([
                'users' => array_map(fn($user) => $user->toArray(), $users),
                'total' => count($users),
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'USER_LIST_ERROR');
        }
    }

    /**
     * GET /api/users/:id
     * Get user details
     */
    public function show(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $userId = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($userId <= 0) {
                $this->error('Invalid user ID', 400, 'INVALID_USER_ID');
                return;
            }

            $user = $this->userRepository->findById($userId);

            if (!$user) {
                $this->error('User not found', 404, 'USER_NOT_FOUND');
                return;
            }

            $this->success(['user' => $user->toArray()]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'USER_SHOW_ERROR');
        }
    }

    /**
     * POST /api/users
     * Create new user
     */
    public function create(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $data = $this->getJsonBody();

            // Validate required fields
            $this->validateRequired($data, ['username', 'password', 'email', 'roles']);

            // Validate username
            if (strlen($data['username']) < 3 || strlen($data['username']) > 50) {
                $this->error('Username must be between 3 and 50 characters', 400, 'INVALID_USERNAME');
                return;
            }

            // Check if username exists
            if ($this->userRepository->usernameExists($data['username'])) {
                $this->error('Username already exists', 400, 'USERNAME_EXISTS');
                return;
            }

            // Validate email
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $this->error('Invalid email address', 400, 'INVALID_EMAIL');
                return;
            }

            // Check if email exists
            if ($this->userRepository->emailExists($data['email'])) {
                $this->error('Email already exists', 400, 'EMAIL_EXISTS');
                return;
            }

            // Validate password
            if (strlen($data['password']) < 8) {
                $this->error('Password must be at least 8 characters', 400, 'WEAK_PASSWORD');
                return;
            }

            // Validate roles
            if (!is_array($data['roles']) || empty($data['roles'])) {
                $this->error('At least one role is required', 400, 'INVALID_ROLES');
                return;
            }

            $validRoles = ['ROLE_ADMIN', 'ROLE_OPERATOR', 'ROLE_USER'];
            foreach ($data['roles'] as $role) {
                if (!in_array($role, $validRoles)) {
                    $this->error('Invalid role: ' . $role, 400, 'INVALID_ROLE');
                    return;
                }
            }

            // Hash password
            $hashedPassword = password_hash($data['password'], PASSWORD_ARGON2ID);

            // Create user
            $userId = $this->userRepository->create(
                username: $data['username'],
                hashedPassword: $hashedPassword,
                email: $data['email'],
                roles: $data['roles'],
                active: $data['active'] ?? true
            );

            // Get created user
            $user = $this->userRepository->findById($userId);

            $this->success(
                ['user' => $user->toArray()],
                'User created successfully',
                201
            );
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'USER_CREATE_ERROR');
        }
    }

    /**
     * PUT /api/users/:id
     * Update user
     */
    public function update(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $userId = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($userId <= 0) {
                $this->error('Invalid user ID', 400, 'INVALID_USER_ID');
                return;
            }

            // Check user exists
            $user = $this->userRepository->findById($userId);
            if (!$user) {
                $this->error('User not found', 404, 'USER_NOT_FOUND');
                return;
            }

            $data = $this->getJsonBody();

            // Validate username if provided
            if (isset($data['username'])) {
                if (strlen($data['username']) < 3 || strlen($data['username']) > 50) {
                    $this->error('Username must be between 3 and 50 characters', 400, 'INVALID_USERNAME');
                    return;
                }

                // Check if username exists (excluding current user)
                if ($this->userRepository->usernameExists($data['username'], $userId)) {
                    $this->error('Username already exists', 400, 'USERNAME_EXISTS');
                    return;
                }
            }

            // Validate email if provided
            if (isset($data['email'])) {
                if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                    $this->error('Invalid email address', 400, 'INVALID_EMAIL');
                    return;
                }

                // Check if email exists (excluding current user)
                if ($this->userRepository->emailExists($data['email'], $userId)) {
                    $this->error('Email already exists', 400, 'EMAIL_EXISTS');
                    return;
                }
            }

            // Validate roles if provided
            if (isset($data['roles'])) {
                if (!is_array($data['roles']) || empty($data['roles'])) {
                    $this->error('At least one role is required', 400, 'INVALID_ROLES');
                    return;
                }

                $validRoles = ['ROLE_ADMIN', 'ROLE_OPERATOR', 'ROLE_USER'];
                foreach ($data['roles'] as $role) {
                    if (!in_array($role, $validRoles)) {
                        $this->error('Invalid role: ' . $role, 400, 'INVALID_ROLE');
                        return;
                    }
                }
            }

            // Update user
            $this->userRepository->update(
                id: $userId,
                username: $data['username'] ?? $user->username,
                email: $data['email'] ?? $user->email,
                roles: $data['roles'] ?? $user->roles,
                active: isset($data['active']) ? (bool)$data['active'] : $user->active
            );

            // Get updated user
            $user = $this->userRepository->findById($userId);

            $this->success(['user' => $user->toArray()], 'User updated successfully');
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'USER_UPDATE_ERROR');
        }
    }

    /**
     * PUT /api/users/:id/password
     * Reset user password
     */
    public function resetPassword(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $userId = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($userId <= 0) {
                $this->error('Invalid user ID', 400, 'INVALID_USER_ID');
                return;
            }

            // Check user exists
            $user = $this->userRepository->findById($userId);
            if (!$user) {
                $this->error('User not found', 404, 'USER_NOT_FOUND');
                return;
            }

            $data = $this->getJsonBody();

            // Validate required fields
            $this->validateRequired($data, ['password']);

            // Validate password
            if (strlen($data['password']) < 8) {
                $this->error('Password must be at least 8 characters', 400, 'WEAK_PASSWORD');
                return;
            }

            // Hash password
            $hashedPassword = password_hash($data['password'], PASSWORD_ARGON2ID);

            // Update password
            $this->userRepository->updatePassword($userId, $hashedPassword);

            $this->success(null, 'Password reset successfully');
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'PASSWORD_RESET_ERROR');
        }
    }

    /**
     * DELETE /api/users/:id
     * Delete user
     */
    public function delete(): void
    {
        try {
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser || !in_array('ROLE_ADMIN', $currentUser->roles)) {
                $this->error('Admin role required', 403, 'FORBIDDEN');
                return;
            }

            $userId = (int)($_SERVER['ROUTE_PARAMS']['id'] ?? 0);

            if ($userId <= 0) {
                $this->error('Invalid user ID', 400, 'INVALID_USER_ID');
                return;
            }

            // Check user exists
            $user = $this->userRepository->findById($userId);
            if (!$user) {
                $this->error('User not found', 404, 'USER_NOT_FOUND');
                return;
            }

            // Prevent deleting self
            if ($userId === $currentUser->id) {
                $this->error('Cannot delete your own account', 400, 'CANNOT_DELETE_SELF');
                return;
            }

            // Prevent deleting last admin
            if (in_array('ROLE_ADMIN', $user->roles)) {
                $allUsers = $this->userRepository->findAll();
                $adminCount = count(array_filter($allUsers, fn($u) => in_array('ROLE_ADMIN', $u->roles)));

                if ($adminCount <= 1) {
                    $this->error('Cannot delete the last admin user', 400, 'LAST_ADMIN');
                    return;
                }
            }

            // Delete user
            $this->userRepository->delete($userId);

            $this->success(['id' => $userId], 'User deleted successfully');
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'USER_DELETE_ERROR');
        }
    }
}
