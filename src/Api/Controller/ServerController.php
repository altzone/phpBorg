<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Exception\PhpBorgException;
use PhpBorg\Service\Server\ServerManager;

/**
 * Server management API controller
 */
class ServerController extends BaseController
{
    private readonly ServerManager $serverManager;

    public function __construct(Application $app)
    {
        $this->serverManager = $app->getServerManager();
    }

    /**
     * GET /api/servers
     * List all servers
     */
    public function list(): void
    {
        try {
            $servers = $this->serverManager->getAllServers();

            $this->success([
                'servers' => array_map(fn($server) => [
                    'id' => $server->id,
                    'name' => $server->name,
                    'hostname' => $server->hostname,
                    'port' => $server->port,
                    'username' => $server->username,
                    'description' => $server->description,
                    'active' => $server->active,
                    'created_at' => $server->createdAt?->format('Y-m-d H:i:s'),
                    'updated_at' => $server->updatedAt?->format('Y-m-d H:i:s'),
                ], $servers),
                'total' => count($servers),
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 'SERVER_LIST_ERROR', 500);
        }
    }

    /**
     * GET /api/servers/:id
     * Get server details with repositories
     */
    public function show(): void
    {
        try {
            $serverId = (int) ($_GET['id'] ?? 0);

            if ($serverId <= 0) {
                $this->error('Invalid server ID', 'INVALID_SERVER_ID', 400);
                return;
            }

            $server = $this->serverManager->getServerById($serverId);

            if (!$server) {
                $this->error('Server not found', 'SERVER_NOT_FOUND', 404);
                return;
            }

            // Get repositories for this server
            $repositories = $this->serverManager->getRepositoriesForServer($serverId);

            $this->success([
                'server' => [
                    'id' => $server->id,
                    'name' => $server->name,
                    'hostname' => $server->hostname,
                    'port' => $server->port,
                    'username' => $server->username,
                    'description' => $server->description,
                    'active' => $server->active,
                    'created_at' => $server->createdAt?->format('Y-m-d H:i:s'),
                    'updated_at' => $server->updatedAt?->format('Y-m-d H:i:s'),
                ],
                'repositories' => array_map(fn($repo) => [
                    'id' => $repo->id,
                    'type' => $repo->type,
                    'repo_path' => $repo->repoPath,
                    'compression' => $repo->compression,
                    'created_at' => $repo->createdAt?->format('Y-m-d H:i:s'),
                ], $repositories),
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 'SERVER_DETAIL_ERROR', 500);
        }
    }

    /**
     * POST /api/servers
     * Create a new server
     */
    public function create(): void
    {
        try {
            // Check admin role
            $user = $_SERVER['USER'] ?? null;
            if (!$user || !in_array('ROLE_ADMIN', $user->roles)) {
                $this->error('Admin role required', 'FORBIDDEN', 403);
                return;
            }

            $data = $this->getJsonBody();

            // Validate required fields
            $this->validateRequired($data, ['name', 'hostname', 'username']);

            // Validate port
            $port = (int) ($data['port'] ?? 22);
            if ($port <= 0 || $port > 65535) {
                $this->error('Invalid port number', 'INVALID_PORT', 400);
                return;
            }

            // Create server
            $serverId = $this->serverManager->createServer(
                name: $data['name'],
                hostname: $data['hostname'],
                port: $port,
                username: $data['username'],
                description: $data['description'] ?? null
            );

            // Get created server
            $server = $this->serverManager->getServerById($serverId);

            $this->success(
                [
                    'server' => [
                        'id' => $server->id,
                        'name' => $server->name,
                        'hostname' => $server->hostname,
                        'port' => $server->port,
                        'username' => $server->username,
                        'description' => $server->description,
                        'active' => $server->active,
                        'created_at' => $server->createdAt?->format('Y-m-d H:i:s'),
                    ],
                ],
                'Server created successfully',
                201
            );
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 'SERVER_CREATE_ERROR', 500);
        }
    }

    /**
     * PUT /api/servers/:id
     * Update server
     */
    public function update(): void
    {
        try {
            // Check admin role
            $user = $_SERVER['USER'] ?? null;
            if (!$user || !in_array('ROLE_ADMIN', $user->roles)) {
                $this->error('Admin role required', 'FORBIDDEN', 403);
                return;
            }

            $serverId = (int) ($_GET['id'] ?? 0);

            if ($serverId <= 0) {
                $this->error('Invalid server ID', 'INVALID_SERVER_ID', 400);
                return;
            }

            // Check server exists
            $server = $this->serverManager->getServerById($serverId);
            if (!$server) {
                $this->error('Server not found', 'SERVER_NOT_FOUND', 404);
                return;
            }

            $data = $this->getJsonBody();

            // Validate port if provided
            if (isset($data['port'])) {
                $port = (int) $data['port'];
                if ($port <= 0 || $port > 65535) {
                    $this->error('Invalid port number', 'INVALID_PORT', 400);
                    return;
                }
            }

            // Update server
            $this->serverManager->updateServer(
                serverId: $serverId,
                name: $data['name'] ?? null,
                hostname: $data['hostname'] ?? null,
                port: isset($data['port']) ? (int) $data['port'] : null,
                username: $data['username'] ?? null,
                description: $data['description'] ?? null,
                active: isset($data['active']) ? (bool) $data['active'] : null
            );

            // Get updated server
            $server = $this->serverManager->getServerById($serverId);

            $this->success([
                'server' => [
                    'id' => $server->id,
                    'name' => $server->name,
                    'hostname' => $server->hostname,
                    'port' => $server->port,
                    'username' => $server->username,
                    'description' => $server->description,
                    'active' => $server->active,
                    'created_at' => $server->createdAt?->format('Y-m-d H:i:s'),
                    'updated_at' => $server->updatedAt?->format('Y-m-d H:i:s'),
                ],
            ], 'Server updated successfully');
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 'SERVER_UPDATE_ERROR', 500);
        }
    }

    /**
     * DELETE /api/servers/:id
     * Delete server
     */
    public function delete(): void
    {
        try {
            // Check admin role
            $user = $_SERVER['USER'] ?? null;
            if (!$user || !in_array('ROLE_ADMIN', $user->roles)) {
                $this->error('Admin role required', 'FORBIDDEN', 403);
                return;
            }

            $serverId = (int) ($_GET['id'] ?? 0);

            if ($serverId <= 0) {
                $this->error('Invalid server ID', 'INVALID_SERVER_ID', 400);
                return;
            }

            // Check server exists
            $server = $this->serverManager->getServerById($serverId);
            if (!$server) {
                $this->error('Server not found', 'SERVER_NOT_FOUND', 404);
                return;
            }

            // Delete server
            $this->serverManager->deleteServer($serverId);

            $this->success(
                ['id' => $serverId],
                'Server deleted successfully'
            );
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 'SERVER_DELETE_ERROR', 500);
        }
    }

    /**
     * GET /api/servers/:id/repositories
     * Get repositories for a server
     */
    public function repositories(): void
    {
        try {
            $serverId = (int) ($_GET['id'] ?? 0);

            if ($serverId <= 0) {
                $this->error('Invalid server ID', 'INVALID_SERVER_ID', 400);
                return;
            }

            $repositories = $this->serverManager->getRepositoriesForServer($serverId);

            $this->success([
                'repositories' => array_map(fn($repo) => [
                    'id' => $repo->id,
                    'server_id' => $repo->serverId,
                    'type' => $repo->type,
                    'repo_path' => $repo->repoPath,
                    'compression' => $repo->compression,
                    'created_at' => $repo->createdAt?->format('Y-m-d H:i:s'),
                ], $repositories),
                'total' => count($repositories),
            ]);
        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 'REPOSITORIES_ERROR', 500);
        }
    }
}
