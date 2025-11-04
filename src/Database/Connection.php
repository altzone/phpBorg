<?php

declare(strict_types=1);

namespace PhpBorg\Database;

use mysqli;
use PhpBorg\Exception\DatabaseException;

/**
 * Secure database connection with prepared statements
 */
final class Connection
{
    private ?mysqli $connection = null;
    private int $queryCount = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password,
        private readonly string $charset = 'utf8mb4',
    ) {
    }

    /**
     * Get database connection (lazy initialization)
     *
     * @throws DatabaseException
     */
    public function getConnection(): mysqli
    {
        if ($this->connection === null) {
            $this->connect();
        }

        return $this->connection;
    }

    /**
     * Execute a prepared statement with parameters
     *
     * @param string $query SQL query with ? placeholders
     * @param array<int, mixed> $params Parameters to bind
     * @return array<int, array<string, mixed>> Query results
     * @throws DatabaseException
     */
    public function execute(string $query, array $params = []): array
    {
        $mysqli = $this->getConnection();
        $stmt = $mysqli->prepare($query);

        if ($stmt === false) {
            throw new DatabaseException("Failed to prepare query: {$mysqli->error}");
        }

        try {
            if (!empty($params)) {
                $types = $this->getParameterTypes($params);
                $stmt->bind_param($types, ...$params);
            }

            if (!$stmt->execute()) {
                throw new DatabaseException("Failed to execute query: {$stmt->error}");
            }

            $this->queryCount++;

            // Get results for SELECT queries
            $result = $stmt->get_result();
            if ($result === false) {
                // No result set (INSERT, UPDATE, DELETE)
                return [];
            }

            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }

            return $rows;
        } finally {
            $stmt->close();
        }
    }

    /**
     * Execute query and return first row
     *
     * @return array<string, mixed>|null
     * @throws DatabaseException
     */
    public function fetchOne(string $query, array $params = []): ?array
    {
        $results = $this->execute($query, $params);
        return $results[0] ?? null;
    }

    /**
     * Execute query and return all rows
     *
     * @return array<int, array<string, mixed>>
     * @throws DatabaseException
     */
    public function fetchAll(string $query, array $params = []): array
    {
        return $this->execute($query, $params);
    }

    /**
     * Execute INSERT/UPDATE/DELETE and return affected rows
     *
     * @throws DatabaseException
     */
    public function executeUpdate(string $query, array $params = []): int
    {
        $mysqli = $this->getConnection();
        $stmt = $mysqli->prepare($query);

        if ($stmt === false) {
            throw new DatabaseException("Failed to prepare query: {$mysqli->error}");
        }

        try {
            if (!empty($params)) {
                $types = $this->getParameterTypes($params);
                $stmt->bind_param($types, ...$params);
            }

            if (!$stmt->execute()) {
                throw new DatabaseException("Failed to execute query: {$stmt->error}");
            }

            $this->queryCount++;

            return $stmt->affected_rows;
        } finally {
            $stmt->close();
        }
    }

    /**
     * Get last insert ID
     */
    public function getLastInsertId(): int
    {
        return $this->getConnection()->insert_id;
    }

    /**
     * Get number of affected rows from last query
     */
    public function getAffectedRows(): int
    {
        return $this->getConnection()->affected_rows;
    }

    /**
     * Get total query count
     */
    public function getQueryCount(): int
    {
        return $this->queryCount;
    }

    /**
     * Begin transaction
     *
     * @throws DatabaseException
     */
    public function beginTransaction(): void
    {
        if (!$this->getConnection()->begin_transaction()) {
            throw new DatabaseException("Failed to begin transaction");
        }
    }

    /**
     * Commit transaction
     *
     * @throws DatabaseException
     */
    public function commit(): void
    {
        if (!$this->getConnection()->commit()) {
            throw new DatabaseException("Failed to commit transaction");
        }
    }

    /**
     * Rollback transaction
     *
     * @throws DatabaseException
     */
    public function rollback(): void
    {
        if (!$this->getConnection()->rollback()) {
            throw new DatabaseException("Failed to rollback transaction");
        }
    }

    /**
     * Establish database connection
     *
     * @throws DatabaseException
     */
    private function connect(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $this->connection = new mysqli(
                $this->host,
                $this->username,
                $this->password,
                $this->database,
                $this->port
            );

            $this->connection->set_charset($this->charset);

            // Set strict mode for better data integrity
            $this->connection->query("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        } catch (\mysqli_sql_exception $e) {
            throw new DatabaseException(
                "Failed to connect to database: {$e->getMessage()}",
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Determine parameter types for bind_param
     */
    private function getParameterTypes(array $params): string
    {
        $types = '';
        foreach ($params as $param) {
            $types .= match (true) {
                is_int($param) => 'i',
                is_float($param) => 'd',
                is_string($param) => 's',
                default => 'b', // blob/binary
            };
        }
        return $types;
    }

    /**
     * Close database connection
     */
    public function close(): void
    {
        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }
    }

    /**
     * Close connection on destruction
     */
    public function __destruct()
    {
        $this->close();
    }
}
