<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace support;

/**
 * Class Request
 * @package support
 */
class Request extends \Webman\Http\Request
{
    private string $requestId = '';
    private ?int $authenticatedUserId = null;
    private string $authenticatedUserRole = 'user';

    public function setRequestId(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function setAuthenticatedUserId(int $userId): void
    {
        $this->authenticatedUserId = $userId;
    }

    public function userId(): ?int
    {
        return $this->authenticatedUserId;
    }

    public function setAuthenticatedUserRole(string $role): void
    {
        $this->authenticatedUserRole = $role;
    }

    public function userRole(): string
    {
        return $this->authenticatedUserRole;
    }
}
