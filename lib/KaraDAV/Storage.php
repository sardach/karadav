<?php

namespace KaraDAV;

use KD2\WebDAV\AbstractStorage;

class Storage extends AbstractStorage
{
	protected Users $users;

	/**
	 * These file names will be ignored when doing a PUT
	 * as they are garbage, coming from some OS
	 */
	const PUT_IGNORE_PATTERN = '!^~(?:lock\.|^\._)|^(?:\.DS_Store|Thumbs\.db|desktop\.ini)$!';

	public function __construct(Users $users)
	{
		$this->users = $users;
	}

	public function getLock(string $uri, ?string $token = null): ?string
	{
		// It is important to check also for a lock on parent directory as we support depth=1
		$sql = 'SELECT scope FROM locks WHERE user = ? AND (uri = ? OR uri = ?)';
		$params = [$this->users->current()->login, $uri, dirname($uri)];

		if ($token) {
			$sql .= ' AND token = ?';
			$params[] = $token;
		}

		$sql .= ' LIMIT 1';

		return DB::getInstance()->firstColumn($sql, ...$params);
	}

	public function lock(string $uri, string $token, string $scope): void
	{
		DB::getInstance()->run('REPLACE INTO locks VALUES (?, ?, ?, ?, datetime(\'now\', \'+5 minutes\'));', $this->users->current()->login, $uri, $token, $scope);
	}

	public function unlock(string $uri, string $token): void
	{
		DB::getInstance()->run('DELETE FROM locks WHERE user = ? AND uri = ? AND token = ?;', $this->users->current()->login, $uri, $token);
	}

	public function list(string $uri, ?array $properties): iterable
	{
		$dirs = glob($this->users->current()->path . $uri . '/*', \GLOB_ONLYDIR);
		$dirs = array_map('basename', $dirs);
		natcasesort($dirs);

		$files = glob($this->users->current()->path . $uri . '/*');
		$files = array_map('basename', $files);
		$files = array_diff($files, $dirs);
		natcasesort($files);

		$files = array_flip(array_merge($dirs, $files));
		$files = array_map(fn($a) => null, $files);
		return $files;
	}

	public function get(string $uri): ?array
	{
		if (!file_exists($this->users->current()->path . $uri)) {
			return null;
		}

		//return ['content' => file_get_contents($this->path . $uri)];
		//return ['resource' => fopen($this->path . $uri, 'r')];
		return ['path' => $this->users->current()->path . $uri];
	}

	public function exists(string $uri): bool
	{
		return file_exists($this->users->current()->path . $uri);
	}

	public function get_file_property(string $uri, string $name, int $depth)
	{
		$target = $this->users->current()->path . $uri;

		switch ($name) {
			case 'DAV::getcontentlength':
				return is_dir($target) ? 0 : filesize($target);
			case 'DAV::getcontenttype':
				return mime_content_type($target);
			case 'DAV::resourcetype':
				return is_dir($target) ? 'collection' : '';
			case 'DAV::getlastmodified':
				if (!$uri && $depth == 0 && is_dir($target)) {
					$mtime = get_directory_mtime($target);
				}
				else {
					$mtime = filemtime($target);
				}

				if (!$mtime) {
					return null;
				}

				return new \DateTime('@' . $mtime);
			case 'DAV::displayname':
				return basename($target);
			case 'DAV::ishidden':
				return basename($target)[0] == '.';
			case 'DAV::getetag':
				if (!$uri && !$depth) {
					$hash = get_directory_size($target) . get_directory_mtime($target);
				}
				else {
					$hash = filemtime($target) . filesize($target);
				}

				return md5($hash . $target);
			case 'DAV::lastaccessed':
				return new \DateTime('@' . fileatime($target));
			case 'DAV::creationdate':
				return new \DateTime('@' . filectime($target));
			// NextCloud stuff
			case self::PROP_OC_ID:
				return $this->nc_direct_id($uri);
			case self::PROP_OC_PERMISSIONS:
				return implode('', [self::PERM_READ, self::PERM_WRITE, self::PERM_CREATE, self::PERM_DELETE, self::PERM_RENAME_MOVE]);
			case self::PROP_OC_SIZE:
				if (is_dir($target)) {
					return get_directory_size($target);
				}
				else {
					return filesize($target);
				}
			default:
				break;
		}

		if (in_array($name, self::NC_PROPERTIES) || in_array($name, self::BASIC_PROPERTIES) || in_array($name, self::EXTENDED_PROPERTIES)) {
			return null;
		}

		return null;
		//return $this->getResourceProperties($uri)->get($name);
	}

	public function properties(string $uri, ?array $properties, int $depth): ?array
	{
		$target = $this->users->current()->path . $uri;

		if (!file_exists($target)) {
			return null;
		}

		if (null === $properties) {
			$properties = array_merge(self::BASIC_PROPERTIES, ['DAV::getetag', self::PROP_OC_ID]);
		}

		$out = [];

		foreach ($properties as $name) {
			$v = $this->get_file_property($uri, $name, $depth);

			if (null !== $v) {
				$out[$name] = $v;
			}
		}

		return $out;
	}

	public function put(string $uri, $pointer): bool
	{
		if (preg_match(self::PUT_IGNORE_PATTERN, basename($uri))) {
			return false;
		}

		$target = $this->users->current()->path . $uri;
		$parent = dirname($target);

		if (is_dir($target)) {
			throw new WebDAV_Exception('Target is a directory', 409);
		}

		if (!file_exists($parent)) {
			mkdir($parent, 0770, true);
		}

		$new = !file_exists($target);
		$delete = false;
		$size = 0;
		$quota = $this->users->quota($this->users->current());

		if (!$new) {
			$size -= filesize($target);
		}

		$tmp_file = '.tmp.' . sha1($target);
		$out = fopen($tmp_file, 'w');

		while (!feof($pointer)) {
			$bytes = fread($pointer, 8192);
			$size += strlen($bytes);

			if ($size > $quota->free) {
				$delete = true;
				break;
			}

			fwrite($out, $bytes);
		}

		fclose($out);
		fclose($pointer);

		if ($delete) {
			@unlink($tmp_file);
			throw new WebDAV_Exception('Your quota is exhausted', 403);
		}
		else {
			rename($tmp_file, $target);
		}

		return $new;
	}

	public function delete(string $uri): void
	{
		$target = $this->users->current()->path . $uri;

		if (!file_exists($target)) {
			throw new WebDAV_Exception('Target does not exist', 404);
		}

		if (is_dir($target)) {
			foreach (glob($target . '/*') as $file) {
				$this->delete(substr($file, strlen($this->users->current()->path)));
			}

			rmdir($target);
		}
		else {
			unlink($target);
		}

		//$this->getResourceProperties($uri)->clear();
	}

	public function copymove(bool $move, string $uri, string $destination): bool
	{
		$source = $this->users->current()->path . $uri;
		$target = $this->users->current()->path . $destination;
		$parent = dirname($target);

		if (!file_exists($source)) {
			throw new WebDAV_Exception('File not found', 404);
		}

		$overwritten = file_exists($target);

		if (!is_dir($parent)) {
			throw new WebDAV_Exception('Target parent directory does not exist', 409);
		}

		if (false === $move) {
			$quota = $this->users->quota($this->users->current());

			if (filesize($source) > $quota->free) {
				throw new WebDAV_Exception('Your quota is exhausted', 403);
			}
		}

		if ($overwritten) {
			$this->delete($destination);
		}

		$method = $move ? 'rename' : 'copy';

		if ($method == 'copy' && is_dir($source)) {
			@mkdir($target, 0770, true);

			foreach ($iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source), \RecursiveIteratorIterator::SELF_FIRST) as $item)
			{
				if ($item->isDir()) {
					@mkdir($target . DIRECTORY_SEPARATOR . $iterator->getSubPathname());
				} else {
					copy($item, $target . DIRECTORY_SEPARATOR . $iterator->getSubPathname());
				}
			}
		}
		else {
			$method($source, $target);

			//$this->getResourceProperties($uri)->move($destination);
		}

		return $overwritten;
	}

	public function copy(string $uri, string $destination): bool
	{
		return $this->copymove(false, $uri, $destination);
	}

	public function move(string $uri, string $destination): bool
	{
		return $this->copymove(true, $uri, $destination);
	}

	public function mkcol(string $uri): void
	{
		if (!$this->users->current()->quota) {
			throw new WebDAV_Exception('Your quota is exhausted', 403);
		}

		$target = $this->users->current()->path . $uri;
		$parent = dirname($target);

		if (file_exists($target)) {
			throw new WebDAV_Exception('There is already a file with that name', 405);
		}

		if (!file_exists($parent)) {
			throw new WebDAV_Exception('The parent directory does not exist', 409);
		}

		mkdir($target, 0770);
	}


	public function getResourceProperties(string $uri): Properties
	{
		if (!isset($this->properties[$uri])) {
			$this->properties[$uri] = new Properties($this->users->current()->login, $uri);
		}

		return $this->properties[$uri];
	}

	public function setProperties(string $uri, string $body): void
	{
		$xml = @simplexml_load_string($body);
		// Select correct namespace if required
		if (!empty(key($xml->getDocNameSpaces()))) {
			$xml = $xml->children('DAV:');
		}

		$db = DB::getInstance();

		$db->exec('BEGIN;');
		$i = 0;

		if (isset($xml->set)) {
			foreach ($xml->set as $prop) {
				$prop = $prop->prop->children();
				$ns = $prop->getNamespaces(true);
				$ns = array_flip($ns);

				if (!key($ns)) {
					throw new WebDAV_Exception('Empty xmlns', 400);
				}

				$this->getResourceProperties($uri)->set(key($ns), $prop->getName(), array_filter($ns, 'trim'), $prop->asXML());
			}
		}

		if (isset($xml->remove)) {
			foreach ($xml->remove as $prop) {
				$prop = $prop->prop->children();
				$ns = $prop->getNamespaces();
				$this->getResourceProperties($uri)->remove(current($ns), $prop->getName());
			}
		}

		$db->exec('END');

		return;
	}
}