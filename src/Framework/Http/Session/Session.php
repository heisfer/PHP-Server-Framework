<?php

/**
 * @copyright Elar Must.
 */

namespace Framework\Http\Session;

use Framework\Database\Database;
use Framework\Logger\Logger;
use Framework\Cache\Cache;
use Framework\Cache\Table;
use Framework\Framework;
use RuntimeException;
use Throwable;

class Session {
    private array $data = [];
    private ?string $id = null;
    private ?int $timeStamp = null;
    private int $expiration = 86400;
    private int $csrfExpiration = 3600;
    private bool $httpOnly = false;
    private bool $secure = false;
    private ?string $sessionPath = '/';
    private ?string $sessionDomain = null;
    private string $cookieName = 'PHPSESSID';
    public const STORAGE_MEMORY = 2;
    public const STORAGE_DATABASE = 1;

    public function __construct (private Framework $framework, private Logger $logger, private Database $database) {
    }

    /**
     * Returns a session.
     * If the session ID is not provided, a new session will be created.
     * If the session ID does not exist, a new session will be created.
     *
     * @param string|null $sessionId Session ID.
     *
     * @return Session Session object.
     */
    public function getSession(string|null $sessionId = null): Session {
        // If the session ID is not provided, then create a new session.
        if ($sessionId === null) {
            return $this->create();
        }

        $inCache = Cache::getTable(self::getTableName())->get($sessionId);
        $data = $inCache ? $inCache : ($this->getDatabase()->select(self::getTableName(), where: ['id' => $sessionId])[0] ?? false);

        // Return a new session if the session does not exist.
        if (!$data) {
            return $this->create();
        }

        $session = $this->clone($sessionId, unserialize($data['data']), $data['timestamp']);
        $timeStamp = $session->getTimestamp();

        // If the session has expired, then return a new session.
        if (($timeStamp - $session->getTimestamp()) > $session->getExpirationSeconds()) {
            $session->delete();
            return $this->create();
        }

        $session->setTimeStamp($timeStamp);
        // Update the timestamp in the cache and database if it has changed.
        if ($timeStamp != $data['timestamp']) {
            if ($inCache !== false) {
                $this->setCached($sessionId, $session->getData(), $timeStamp);
            }

            // In coroutine to do it async.
            go(function () use ($sessionId, $timeStamp) {
                $this->getDatabase()->update(self::getTableName(), ['timestamp' => $timeStamp], ['id' => $sessionId]);
            });
        }

        return $session;
    }

    /**
     * Creates a new session with the given data and stores it in the database.
     *
     * @param array $data Session data.
     *
     * @throws RuntimeException If the session creation in the database fails.
     * @return Session New session.
     */
    public function create(array $data = []): Session {
        $timeStamp = time();
        $sessionId = $this->generateSessionId();
        $session = $this->clone($sessionId, $data, $timeStamp);

        // Save to database, if data is not empty.
        if ($session->getData() !== []) {
            $insertedId = $session->getDatabase()->insert(self::getTableName(), ['id' => $sessionId, 'data' => serialize($data), 'timestamp' => $timeStamp]);
            if ($insertedId === false) {
                throw new RuntimeException('Failed to save a session to database!');
            }
        }

        $this->setCached($sessionId, $data, $timeStamp);

        return $session;
    }

    /**
     * Saves the session data to the database.
     *
     * @throws RuntimeException If the session is not instantiated or fails to save to the database.
     * @return Session Updated session object.
     */
    public function save(): Session {
        if ($this->id() === null) {
            throw new RuntimeException('Cannot save non-instanciated session.');
        }

        $timeStamp = time();

        $this->setCached($this->id(), $this->getData(), $this->getTimestamp());

        // There is no need to save an empty session to database.
        if ($this->getData() === []) {
            return $this;
        }

        go(function () use ($timeStamp) {
            $serializedData = serialize($this->getData());
            $this->getDatabase()->query('
                INSERT INTO
                    ' . self::getTableName() . '
                SET
                    id = ?,
                    data = ?,
                    timestamp = ?
                ON DUPLICATE KEY UPDATE
                    data = ?,
                    timestamp = ?
            ', [
                $this->id(),
                $serializedData,
                $timeStamp,
                $serializedData,
                $timeStamp
            ]);
        });
        return $this;
    }

    /**
     * Sets the data for the session.
     *
     * @param array $data Data to be set for the session.
     * @param bool $merge = false Whether to replace existing or merge.
     *
     * @throws RuntimeException If the session is not instantiated.
     * @return Session Updated session object.
     */
    public function setData(array $data, bool $merge = true): Session {
        if ($this->id() === null) {
            throw new RuntimeException('Cannot set non-instantiated session.');
        }

        $this->setTimeStamp(time());
        $this->data = $merge ? array_replace_recursive($this->getData(), $data) : $data;

        // We might as well delete it, if data is empty.
        if ($this->getData() === []) {
            $this->delete();
            return $this;
        }

        $this->setCached($this->id(), $this->getData(), $this->getTimestamp());

        return $this;
    }

    /**
     * Deletes the session from the database and removes it from the session cache.
     *
     * @throws RuntimeException If the session fails to be deleted from the database.
     * @return Session Deleted session.
     */
    public function delete(): Session {
        $status = $this->getDatabase()->delete(self::getTableName(), ['id' => $this->id()]);
        if (!$status) {
            throw new RuntimeException('Failed to delete session from database!');
        }

        Cache::getTable(self::getTableName())->del($this->id());

        return $this;
    }

    /**
     * Checks if a record with the given ID exists in the cache/database.
     *
     * @param string $id Session id.
     *
     * @return bool True if the record exists, false otherwise.
     */
    public function exists(string $sessionId): bool {
        return (bool) $this->sessionStorageLocation($sessionId);
    }

    /**
     * Returns a random 32 character session id.
     *
     * @return string
     */
    public function generateSessionId(): string {
        while (true) {
            $randomString = base64_encode(random_bytes(45));

            if (!$this->sessionStorageLocation($randomString)) {
                return $randomString;
            }
        }
    }

    /**
     * Sets the cached session data for a given session ID.
     *
     * @param string $sessionId Session ID.
     * @param array $data Session data to be cached.
     * @param int $timeStamp Timestamp for the session data.
     *
     * @return void
     */
    private function setCached(string $sessionId, array $data, int $timeStamp): void {
        try {
            $existingData = Cache::getTable(self::getTableName())->get($sessionId)['data'] ?? serialize([]);
            $newData = array_replace_recursive(unserialize($existingData), $data);

            Cache::getTable(self::getTableName())->set($sessionId, ['data' => serialize($newData), 'timestamp' => $timeStamp]);
        } catch (Throwable $e) {
            $this->logger->debug('Unable to save session to cache!', identifier: 'framework');
            $this->logger->debug($e, identifier: 'framework');
        }
    }

    /**
     * Clones the session object with the provided id, data, and timestamp.
     *
     * @param string $id Session id.
     * @param mixed $data Session data.
     * @param int $timeStamp Session timestamp.
     *
     * @return Session Cloned session object.
     */
    private function clone(string $id, $data, $timeStamp): Session {
        $session = clone $this;
        $session->id = $id;
        $session->data = $data;
        $session->timeStamp = $timeStamp;
        return $session;
    }

    /**
     * Retrieves data from the session.
     *
     * @param array $keys An optional array of keys to retrieve. If not provided, all data will be returned.
     *
     * @throws RuntimeException If any of the provided keys are invalid.
     * @return array Retrieved data.
     */
    public function getData(array $keys = []): array {
        if (!$keys) {
            return $this->data;
        }

        $invalidKeys = array_diff($keys, array_keys($this->data));
        if ($invalidKeys) {
            throw new RuntimeException('Invalid data keys: ' . implode(', ', $invalidKeys));
        }

        return array_intersect_key($this->data, array_flip($keys));
    }

    /**
     * Generate and return a new CSRF token.
     *
     * @throws RuntimeException if the session is not instantiated.
     * @return string New CSRF token.
     */
    public function generateCsrfToken(): string {
        if ($this->id() === null) {
            throw new RuntimeException('Cannot set non-instantiated session.');
        }

        $key = base64_encode(random_bytes(45));
        $data[$key] = ['created' => time(), 'expiration' => $this->csrfExpiration];
        $this->setData(['csrfTokens' => $data]);
        return $key;
    }

    /**
     * Checks if the provided CSRF token is valid.
     * Cleans up expired CSRF tokens.
     *
     * @param null|string $token
     *
     * @throws RuntimeException if the session is not instantiated.
     * @return bool
     */
    public function validateCsrfToken(?string $token): bool {
        if ($this->id() === null) {
            throw new RuntimeException('Cannot set non-instantiated session.');
        }

        $return = false;
        foreach ($this->getData()['csrfTokens'] ?? [] as $sessionToken => $tokenData) {
            if (time() - $tokenData['created'] < $tokenData['expiration']) {
                if ($token === $sessionToken) {
                    unset($this->csrfTokens[$sessionToken]);
                    $return = true;
                }
            } else {
                unset($this->csrfTokens[$sessionToken]);
            }
        }

        return $return;
    }

    /**
     * Sets the expiration time for the CSRF token.
     *
     * @param int $expiration CSRF token expiration time in seconds.
     *
     * @return void
     */
    public function setCsrfExpiration(int $expiration): void {
        $this->csrfExpiration = $expiration;
    }

    /**
     * Get an array of all available model data keys.
     *
     * @return array An array of model data keys.
     */
    public function getDataKeys(): array {
        return array_keys($this->getData());
    }

    /**
     * Returns Session::STORAGE_MEMORY, if session is stored in memory,
     * Session::STORAGE_DATABASE if session is stored in databse only,
     * false if session does not exist.
     *
     * @param string $sessionId Session ID.
     *
     * @return int|bool
     */
    public function sessionStorageLocation(string $sessionId): int|bool {
        if (Cache::getTable(self::getTableName())->exists($sessionId)) {
            return $this::STORAGE_MEMORY;
        }

        if ($this->database->select(self::getTableName(), ['id'], ['id' => $sessionId])) {
            return $this::STORAGE_DATABASE;
        }

        return false;
    }

    /**
     * Get the session ID.
     *
     * @return null|string Session ID or null if not set.
     */
    public function id(): null|string {
        return $this->id;
    }

    /**
     * Returns the expiration time of the session in seconds.
     *
     * @return int Session expiration time in seconds.
     */
    public function getExpirationSeconds(): int {
        return $this->expiration;
    }

    /**
     * Sets the expiration time for the session in seconds.
     *
     * @param int $seconds Session expiration time in seconds.
     *
     * @return void
     */
    public function setExpirationSeconds(int $seconds): void {
        $this->expiration = $seconds;
    }

    /**
     * Returns the name of the table used for storing session data
     * for database and cache storage.
     *
     * @return string Table name used to cache and database storage
     */
    public static function getTableName(): string {
        return 'sessions';
    }

    /**
     * Retrieves the database object associated with the session.
     *
     * @return Database Database used for session storage.
     */
    public function getDatabase(): Database {
        return $this->database;
    }

    /**
     * Get the timestamp of the session.
     *
     * @return null|int Session timestamp.
     */
    public function getTimestamp(): null|int {
        return $this->timeStamp;
    }

    /**
     * Set the timestamp for the session.
     *
     * @param int $timeStamp Timestamp to set.
     *
     * @throws RuntimeException If the timestamp is invalid.
     * @return Session
     */
    public function setTimeStamp(int $timeStamp): Session {
        // Validate timestamp against the UNIX timestamp.
        if ($timeStamp < 0) {
            throw new RuntimeException('Invalid timestamp: ' . $timeStamp);
        }

        $this->timeStamp = $timeStamp;
        return $this;
    }

    /**
     * Sets the flag indicating whether the session cookie should be accessible only through the HTTP protocol.
     *
     * @param bool $httpOnly Whether the session cookie should be accessible only through the HTTP protocol.
     *
     * @return void
     */
    public function setHttpOnly(bool $httpOnly): void {
        $this->httpOnly = $httpOnly;
    }

    /**
     * Returns whether the session cookie is HTTP only.
     *
     * @return bool True if the session cookie is HTTP only, false otherwise.
     */
    public function getHttpOnly(): bool {
        return $this->httpOnly;
    }

    /**
     * Sets the secure flag for the session.
     *
     * @param bool $secure Whether the session should be secure or not.
     *
     * @return void
     */
    public function setSecure(bool $secure): void {
        $this->secure = $secure;
    }

    /**
     * Get the value of the secure flag for the session.
     *
     * @return bool Value of secure flag.
     */
    public function getSecure(): bool {
        return $this->secure;
    }

    /**
     * Sets the session path.
     *
     * @param null|string $path Path to set for the session.
     *
     * @return void
     */
    public function setSessionPath(null|string $path): void {
        $this->sessionPath = $path;
    }

    /**
     * Returns the session path.
     *
     * @return null|string Session path.
     */
    public function getSessionPath(): null|string {
        return $this->sessionPath;
    }

    /**
     * Sets the session domain.
     *
     * @param null|string $domain Domain to set for the session.
     *
     * @return void
     */
    public function setSessionDomain(string|null $domain): void {
        $this->sessionDomain = $domain;
    }

    /**
     * Retrieves the session domain.
     *
     * @return null|string Domain for the session.
     */
    public function getSessionDomain(): null|string {
        return $this->sessionDomain;
    }

    /**
     * Sets the name of the session cookie.
     *
     * @param string $cookieName Session cookie name.
     *
     * @throws RuntimeException If the cookie name is invalid.
     * @return void
     */
    public function setCookieName(string $cookieName): void {
        $cookieName = trim($cookieName);
        if ($cookieName === '' || strpos($cookieName, ' ') !== false) {
            throw new RuntimeException('Invalid cookie name: ' . $cookieName);
        }

        $this->cookieName = $cookieName;
    }

    /**
     * Returns the name of the cookie used for session management.
     * Defaults to 'PHPSESSID'.
     *
     * @return string Session cookie name.
     */
    public function getCookieName(): string {
        return $this->cookieName;
    }

    /**
     * Get the value of the data key.
     *
     * @param string $name Data key name.
     *
     * @throws RuntimeException If the data key does not exist.
     * @return mixed Data value.
     */
    public function __get($name) {
        return $this->getData([$name]);
    }

    /**
     * Retrieves the session cache table.
     *
     * @return Table Cache table containing session IDs.
     */
    public function getCacheTable(): Table {
        return Cache::getTable(self::getTableName());
    }

    /**
     * Sets the value of a model data key.
     *
     * @param string $name Data key name.
     * @param mixed $value Value to be set.
     *
     * @return void
     */
    public function __set($name, $value): void {
        $this->setData([$name => $value]);
    }

    /**
     * Checks if a data key is set.
     *
     * @param string $name Data key to check.
     *
     * @return bool Returns true if the data key is set, false otherwise.
     */
    public function __isset($name): bool {
        return array_key_exists($name, $this->getData());
    }

    /**
     * Unsets a model's data key.
     *
     * @param string $name Data key to unset.
     *
     * @return void
     */
    public function __unset($name): void {
        unset($this->data[$name]);
    }

    /**
     * Retursn mode's data array.
     *
     * @return array Session data array.
     */
    public function __toArray(): array {
        return $this->getData();
    }

    /**
     * Returns a JSON representation of session data.
     *
     * @return string JSON representation of session data.
     */
    public function __toString(): string {
        return json_encode($this->getData());
    }
}
