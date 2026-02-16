<?php

/*
 * Copyright (C) 2007 lenny@mondogrigio.cjb.net
 *
 * This file is part of PJBS (http://sourceforge.net/projects/pjbs)
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

declare(strict_types=1);

class PJBridge
{
	private mixed $sock = null;

    /**
     * Initializes a new PJBridge instance and opens a socket connection to the
     * server.
     *
     * @param string $host The hostname of the server to connect to.
     * @param int $port The port number of the server to connect to.
     * @param string $jdbcEncoding The character encoding used by the JDBC driver.
     * @param string $appEncoding The character encoding used by the application.
     */
	public function __construct(
		string $host = 'localhost',
		int $port = 4444,
		private string $jdbcEncoding = 'ascii',
		private string $appEncoding = 'ascii'
	) {
		$this->sock = fsockopen($host, $port);
	}

    /**
     * Closes the socket connection to the server when the object is destroyed.
     */
	public function __destruct()
	{
		fclose($this->sock);
	}

    /**
     * Parses a reply from the server, converting from JDBC encoding to
     * application encoding and base64 decoding.
     *
     * @return array The reply from the server, as an array of tokens.
     */
	private function parseReply(): array
	{
		$il = explode(' ', fgets($this->sock));
		$ol = [];

		foreach ($il as $value) {
			$ol[] = iconv(
                $this->jdbcEncoding,
                $this->appEncoding,
                base64_decode($value)
            );
        }

		return $ol;
	}

    /**
     * Encodes a command as base64 and sends it to the server, then parses the
     * reply.
     *
     * @param array $cmdA The command to send, as an array of tokens.
     * @return array The reply from the server, as an array of tokens.
     */
	private function exchange(array $cmdA): array
	{
		$cmdS = '';

		foreach ($cmdA as $tok) {
			$cmdS .= base64_encode(
                iconv($this->appEncoding, $this->jdbcEncoding, $tok)
            ).' ';
        }

		$cmdS = substr($cmdS, 0, -1)."\n";
		fwrite($this->sock, $cmdS);
		return $this->parseReply();
	}

    /**
     * Connects to the database using the provided JDBC URL, username, and password.
     *
     * @param string $url The JDBC URL to connect to.
     * @param string $user The username to use for authentication.
     * @param string $pass The password to use for authentication.
     * @return bool True if the connection was successful, false otherwise.
     */
	public function connect(
        #[\SensitiveParameter] string $url,
        #[\SensitiveParameter] string $user,
        #[\SensitiveParameter] string $pass
    ): bool {
		$reply = $this->exchange(['connect', $url, $user, $pass]);

		if ($reply[0] === 'ok') {
			return true;
        }
		return false;
	}

    /**
     * Executes a SQL query on the connected database.
     *
     * @param string $query The SQL query to execute.
     * @return string|false The result of the query, or false on failure.
     */
	public function exec(string $query): string|false
	{
		$cmdA = ['exec', $query];

		if (func_num_args() > 1) {
			$args = func_get_args();
			for ($i = 1; $i < func_num_args(); ++$i) {
				$cmdA[] = $args[$i];
            }
		}

		$reply = $this->exchange($cmdA);

		if ($reply[0] === 'ok') {
			return $reply[1];
        }
		return false;
	}

    /**
     * Fetches a row from the result set identified by the provided result set
     * identifier.
     *
     * @param string $res The result set identifier.
     * @return array|false The fetched row as an associative array, or false on failure.
     */
	public function fetchArray(string $res): array|false
	{
		$reply = $this->exchange(['fetch_array', $res]);

		if ($reply[0] === 'ok') {
			$row = [];
			for ($i = 0; $i < $reply[1]; ++$i) {
				$col = $this->parseReply();
				$row[$col[0]] = $col[1];
			}
			return $row;
		}
		return false;
	}

    /**
    * Frees the result set identified by the provided result set identifier.
    *
    * @param string $res The result set identifier.
    * @return bool True if the result set was successfully freed, false otherwise.
    */
	public function freeResult(string $res): bool
	{
		$reply = $this->exchange(['free_result', $res]);

		if ($reply[0] === 'ok') {
			return true;
        }
		return false;
	}
}
