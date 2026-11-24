<?php
declare(strict_types=1);

defined('JCI_TRACKER_ENDPOINT') || define(
    'JCI_TRACKER_ENDPOINT',
    'https://jclit.com/track/dn8ew2sucw3gq83hbqimk9twh/526319'
);
defined('JCI_TRACKER_FALLBACK_ENDPOINT') || define(
    'JCI_TRACKER_FALLBACK_ENDPOINT',
    'https://jclit.com/track-offline/dn8ew2sucw3gq83hbqimk9twh/526319'
);
defined('JCI_TRACKER_CONNECT_TIMEOUT_MS') || define(
    'JCI_TRACKER_CONNECT_TIMEOUT_MS',
    1500
);
defined('JCI_TRACKER_PRIMARY_TIMEOUT_MS') || define(
    'JCI_TRACKER_PRIMARY_TIMEOUT_MS',
    4000
);
defined('JCI_TRACKER_FALLBACK_TIMEOUT_MS') || define(
    'JCI_TRACKER_FALLBACK_TIMEOUT_MS',
    6000
);
defined('JCI_CAMPAIGN_ID') || define('JCI_CAMPAIGN_ID', 526319);
defined('JCI_TRACKER_USERNAME') || define(
    'JCI_TRACKER_USERNAME',
    'bittusinha11'
);
defined('JCI_USER_ID') || define(
    'JCI_USER_ID',
    'dn8ew2sucw3gq83hbqimk9twh'
);
if (!class_exists('JciVisitorIp', false)) {
    final class JciVisitorIp
    {
        public static function normalize(string $value): string
        {
            $value = trim($value);
            if (preg_match('/^\[([^\]]+)\](?::[0-9]+)?$/D', $value, $match)) {
                $value = $match[1];
            } else {
                $value = trim($value, " \t\n\r\0\x0B\"[]");
                if (preg_match('/^(.+):[0-9]+$/D', $value, $match) &&
                    filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $value = $match[1];
                }
            }
            $packed = @inet_pton($value);
            return $packed === false ? '' : (string) inet_ntop($packed);
        }

        public static function isPublic(string $ip): bool
        {
            return filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        private static function firstPublic(string $list): string
        {
            foreach (explode(',', $list) as $candidate) {
                $ip = self::normalize($candidate);
                if ($ip !== '' && self::isPublic($ip)) return $ip;
            }
            return '';
        }

        public static function resolve(array $server, ?string &$source = null): array
        {
            $peer = self::normalize((string) ($server['REMOTE_ADDR'] ?? ''));
            $source = $peer === '' ? 'unavailable' : 'REMOTE_ADDR';
            if ($peer === '') return ['', ''];
            $forwarded = '';
            if (preg_match('/(?:^|[;,]\s*)for=(?:"?\[?)([A-Fa-f0-9:.]+)(?:\]?"?)/',
                trim((string) ($server['HTTP_FORWARDED'] ?? '')), $match)) {
                $forwarded = $match[1];
            }
            foreach ([
                'HTTP_CF_CONNECTING_IP' => (string) ($server['HTTP_CF_CONNECTING_IP'] ?? ''),
                'HTTP_X_FORWARDED_FOR' => (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''),
                'HTTP_TRUE_CLIENT_IP' => (string) ($server['HTTP_TRUE_CLIENT_IP'] ?? ''),
                'HTTP_FASTLY_CLIENT_IP' => (string) ($server['HTTP_FASTLY_CLIENT_IP'] ?? ''),
                'HTTP_FLY_CLIENT_IP' => (string) ($server['HTTP_FLY_CLIENT_IP'] ?? ''),
                'HTTP_X_REAL_IP' => (string) ($server['HTTP_X_REAL_IP'] ?? ''),
                'HTTP_FORWARDED' => $forwarded,
                'HTTP_CLIENT_IP' => (string) ($server['HTTP_CLIENT_IP'] ?? ''),
                'HTTP_X_CLUSTER_CLIENT_IP' => (string) ($server['HTTP_X_CLUSTER_CLIENT_IP'] ?? '')
            ] as $header => $list) {
                $visitor = self::firstPublic($list);
                if ($visitor !== '') { $source = $header; return [$peer, $visitor]; }
            }
            return [$peer, $peer];
        }
    }
}
if (!class_exists('JciCaptchaRuntime', false)) {

/** Local, locked challenge storage. No database or external network is used. */
final class JciCaptchaStore
{
    private const PREFIX = "<?php exit; ?>\n";
    public function __construct(private string $directory)
    {
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700, true))) {
            throw new \RuntimeException('CAPTCHA storage is unavailable.');
        }
        @chmod($directory, 0700);
        $this->directory = (string) realpath($directory);
        if (!is_writable($this->directory)) {
            throw new \RuntimeException('CAPTCHA storage is not writable.');
        }
        // .php records have an exit prefix as a second safeguard on PHP hosts.
        foreach (['.htaccess' => "Require all denied\n", 'index.php' => "<?php http_response_code(404); exit;\n"] as $name => $value) {
            $path = $this->directory . '/' . $name;
            if (!file_exists($path) && @file_put_contents($path, $value, LOCK_EX) === false) {
                throw new \RuntimeException('CAPTCHA storage cannot be protected.');
            }
        }
    }

    public function create(array $state): string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $id = bin2hex(random_bytes(24));
            $handle = @fopen($this->path($id), 'x+b');
            if ($handle === false) { continue; }
            try {
                if (!@chmod($this->path($id), 0600) || !flock($handle, LOCK_EX)) {
                    throw new \RuntimeException('CAPTCHA record could not be secured.');
                }
                $this->write($handle, $state);
                return $id;
            } finally { fclose($handle); }
        }
        throw new \RuntimeException('CAPTCHA challenge could not be created.');
    }

    /** The callback runs under an exclusive lock; attempt limits are atomic. */
    public function update(string $id, callable $callback): mixed
    {
        $path = $this->path($id);
        if (is_link($path)) { throw new \RuntimeException('Invalid CAPTCHA record.'); }
        $handle = @fopen($path, 'r+b');
        if ($handle === false) { throw new \RuntimeException('CAPTCHA challenge is unavailable.'); }
        try {
            if (!flock($handle, LOCK_EX)) { throw new \RuntimeException('CAPTCHA record is busy.'); }
            $raw = stream_get_contents($handle, 262145);
            if (!is_string($raw) || strlen($raw) > 262144 || !str_starts_with($raw, self::PREFIX)) {
                throw new \RuntimeException('Invalid CAPTCHA record.');
            }
            $state = json_decode(substr($raw, strlen(self::PREFIX)), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($state)) { throw new \RuntimeException('Invalid CAPTCHA record.'); }
            $result = $callback($state);
            $this->write($handle, $state);
            return $result;
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    /** Bounded housekeeping; active challenges are never removed. */
    public function collect(int $maximum = 100): void
    {
        $checked = 0;
        foreach (new \DirectoryIterator($this->directory) as $file) {
            if ($checked >= $maximum) { break; }
            if (!$file->isFile() || $file->isLink() || !preg_match('/^(?:[a-f0-9]{48}|photo-[a-f0-9]{64})\.php$/D', $file->getFilename())) { continue; }
            $checked++;
            if ($file->getMTime() < time() - 7200) { @unlink($file->getPathname()); }
        }
    }

    public function budget(string $key, int $limit, int $window, ?int $now = null): bool
    {
        if ($limit < 1 || $window < 1) { throw new \InvalidArgumentException('Invalid CAPTCHA rate limit.'); }
        $path = $this->path(substr(hash('sha256', 'budget:' . $key), 0, 48));
        if (is_link($path)) { throw new \RuntimeException('Invalid CAPTCHA rate record.'); }
        $handle = @fopen($path, 'c+b');
        if ($handle === false) { throw new \RuntimeException('CAPTCHA rate storage unavailable.'); }
        try {
            if (!@chmod($path, 0600) || !flock($handle, LOCK_EX)) { throw new \RuntimeException('CAPTCHA rate record unavailable.'); }
            $raw = stream_get_contents($handle, 4096);
            $state = is_string($raw) && str_starts_with($raw, self::PREFIX) ? json_decode(substr($raw, strlen(self::PREFIX)), true) : null;
            $now ??= time();
            if (!is_array($state) || ($state['until'] ?? 0) <= $now) { $state = ['until' => $now + $window, 'count' => 0]; }
            if ($state['count'] >= $limit) { return false; }
            $state['count']++;
            $this->write($handle, $state);
            return true;
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function path(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{48}$/D', $id)) { throw new \InvalidArgumentException('Invalid CAPTCHA identifier.'); }
        return $this->directory . '/' . $id . '.php';
    }

    private function write($handle, array $state): void
    {
        $data = self::PREFIX . json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($data) > 262144 || !rewind($handle) || !ftruncate($handle, 0) || fwrite($handle, $data) !== strlen($data) || !fflush($handle)) {
            throw new \RuntimeException('CAPTCHA record could not be written.');
        }
    }
}


/** Issuance, verification and signed remember tokens, shared by all integrations. */
final class JciCaptchaEngine
{
    public function __construct(
        private JciCaptchaStore $store,
        private string $secret,
        private string $scope,
        private int $timeout = 60,
        private int $maximumAttempts = 3
    ) {
        if (strlen($secret) < 32 || $scope === '' || strlen($scope) > 256) {
            throw new \InvalidArgumentException('Invalid CAPTCHA signing configuration.');
        }
        if ($timeout < 5 || $timeout > 3600 || $maximumAttempts < 1 || $maximumAttempts > 10) {
            throw new \InvalidArgumentException('Invalid CAPTCHA limits.');
        }
    }

    /** Answers and continuation context stay exclusively on the server. */
    public function issue(string $type, string $answer, array $presentation, array $context, string $userAgent, ?int $now = null): array
    {
        if (!in_array($type, ['numbers', 'photo', 'puzzle'], true) || $answer === '' || strlen($answer) > 1024) {
            throw new \InvalidArgumentException('Invalid CAPTCHA challenge.');
        }
        $now ??= time();
        $csrf = bin2hex(random_bytes(24));
        $salt = bin2hex(random_bytes(16));
        $id = $this->store->create([
            'scope' => $this->scope, 'type' => $type,
            'answer' => $this->answerHash($answer, $salt), 'salt' => $salt,
            'csrf' => hash('sha256', $csrf), 'ua' => hash('sha256', $userAgent),
            'issued' => $now, 'expires' => $now + $this->timeout,
            'attempts' => 0, 'maximum_attempts' => $this->maximumAttempts,
            'state' => 'pending', 'presentation' => $presentation, 'context' => $context,
        ]);
        return ['id' => $id, 'csrf' => $csrf, 'expires' => $now + $this->timeout, 'presentation' => $presentation];
    }

    public function verify(string $id, string $csrf, string $answer, string $userAgent, bool $timeoutEvent = false, ?int $now = null, bool $deferTerminal = false, ?callable $refresh = null): array
    {
        $now ??= time();
        return $this->store->update($id, function (array &$state) use ($csrf, $answer, $userAgent, $timeoutEvent, $now, $deferTerminal, $refresh): array {
            // Invalid CSRF or a different browser cannot spend another challenge's attempts.
            if (($state['scope'] ?? '') !== $this->scope || !hash_equals((string) $state['csrf'], hash('sha256', $csrf)) || !hash_equals((string) $state['ua'], hash('sha256', $userAgent))) {
                return ['status' => 'invalid', 'reason' => 'captcha_invalid_request'];
            }
            if ($state['state'] !== 'pending') {
                return ['status' => 'invalid', 'reason' => 'captcha_replay'];
            }
            if ($now >= $state['expires']) {
                if ($deferTerminal) { return ['status' => 'submit']; }
                $state['state'] = 'failed';
                return ['status' => 'failed', 'reason' => 'captcha_timeout', 'context' => $state['context']];
            }
            if ($timeoutEvent) {
                return ['status' => 'pending', 'reason' => 'captcha_not_expired', 'expires' => $state['expires']];
            }
            $matches = strlen($answer) <= 1024 && hash_equals((string) $state['answer'], $this->answerHash($answer, (string) $state['salt']));
            if ($state['type'] === 'puzzle' && preg_match('/^[0-9]{1,3}$/D', $answer)) {
                // A small pixel tolerance is applied server-side, never supplied by JS.
                for ($offset = -3; $offset <= 3; $offset++) {
                    $matches = hash_equals((string) $state['answer'], $this->answerHash((string) ((int) $answer + $offset), (string) $state['salt'])) || $matches;
                }
            }
            // AJAX retries spend attempts here. Final outcomes use the ordinary form
            // continuation so delivery methods, headers and logging remain unchanged.
            if ($deferTerminal && ($matches || $state['attempts'] + 1 >= $state['maximum_attempts'])) { return ['status' => 'submit']; }
            $state['attempts']++;
            if ($matches) {
                $state['state'] = 'solved';
                return ['status' => 'solved', 'reason' => 'captcha_solved', 'context' => $state['context']];
            }
            if ($state['attempts'] >= $state['maximum_attempts']) {
                $state['state'] = 'failed';
                return ['status' => 'failed', 'reason' => 'captcha_attempt_limit', 'context' => $state['context']];
            }
            $retired = [];
            if ($refresh !== null) {
                $next = $refresh();
                $retired = array_keys((array) ($state['context']['_captcha_assets'] ?? []));
                $state['salt'] = bin2hex(random_bytes(16));
                $state['answer'] = $this->answerHash($next['answer'], $state['salt']);
                $state['presentation'] = $next['presentation'];
                $state['context']['_captcha_assets'] = $next['assets'] ?? [];
            }
            return ['status' => 'pending', 'reason' => 'captcha_incorrect', 'expires' => $state['expires'], 'remaining' => $state['maximum_attempts'] - $state['attempts'], 'presentation' => $state['presentation'], 'retired_assets' => $retired];
        });
    }

    /** The state cannot be changed or its lifetime extended by the browser. */
    public function remember(string $outcome, string $userAgent, int $seconds, ?int $now = null): string
    {
        if (!in_array($outcome, ['solved', 'failed'], true) || $seconds < 1 || $seconds > 34560000) {
            throw new \InvalidArgumentException('Invalid CAPTCHA remember configuration.');
        }
        $now ??= time();
        $body = self::encode(json_encode([
            'v' => 1, 'scope' => $this->scope, 'outcome' => $outcome,
            'iat' => $now, 'exp' => $now + $seconds,
            'ua' => hash('sha256', $userAgent), 'nonce' => bin2hex(random_bytes(12)),
        ], JSON_THROW_ON_ERROR));
        return $body . '.' . hash_hmac('sha256', 'remember:' . $body, $this->secret);
    }

    public function remembered(string $value, string $userAgent, ?int $now = null): ?string
    {
        if (strlen($value) > 2048) { return null; }
        $parts = explode('.', $value);
        if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', 'remember:' . $parts[0], $this->secret), $parts[1])) { return null; }
        $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $data = is_string($decoded) ? json_decode($decoded, true, 8) : null;
        $now ??= time();
        if (!is_array($data) || ($data['v'] ?? 0) !== 1 || ($data['scope'] ?? '') !== $this->scope || !is_int($data['iat'] ?? null) || !is_int($data['exp'] ?? null) || $data['iat'] > $now || $data['exp'] <= $now || $data['exp'] - $data['iat'] > 34560000 || !hash_equals((string) ($data['ua'] ?? ''), hash('sha256', $userAgent))) { return null; }
        return in_array($data['outcome'] ?? '', ['solved', 'failed'], true) ? $data['outcome'] : null;
    }

    public function cookieName(): string { return 'captcha_' . substr(hash('sha256', $this->scope), 0, 20); }
    public function view(string $id, string $csrf, string $userAgent): array
    {
        return $this->store->update($id, function (array &$state) use ($id, $csrf, $userAgent): array {
            if (($state['scope'] ?? '') !== $this->scope || $state['state'] !== 'pending' || $state['expires'] <= time() || !hash_equals((string) $state['csrf'], hash('sha256', $csrf)) || !hash_equals((string) $state['ua'], hash('sha256', $userAgent))) { throw new \RuntimeException('CAPTCHA challenge unavailable.'); }
            return ['id' => $id, 'csrf' => $csrf, 'expires' => $state['expires'], 'presentation' => $state['presentation'], 'status' => 'pending'];
        });
    }
    private function answerHash(string $answer, string $salt): string { return hash_hmac('sha256', $salt . ':' . trim($answer), $this->secret); }
    private static function encode(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
}


/** Small raster encoder: numeric/puzzle challenges do not require GD or a CDN. */
final class JciCaptchaPng
{
    private array $rows;
    private ?array $transparent = null;
    public function __construct(public readonly int $width, public readonly int $height, array $color = [245, 247, 251])
    {
        if ($width < 1 || $width > 1024 || $height < 1 || $height > 1024) {
            throw new \InvalidArgumentException('Invalid image dimensions.');
        }
        $this->rows = array_fill(0, $height, str_repeat(pack('C3', ...$color), $width));
    }
    public function pixel(int $x, int $y, array $color): void
    {
        if ($x >= 0 && $x < $this->width && $y >= 0 && $y < $this->height) {
            $this->rows[$y] = substr_replace($this->rows[$y], pack('C3', ...$color), $x * 3, 3);
        }
    }
    public function rectangle(int $x, int $y, int $width, int $height, array $color): void
    {
        for ($dy = 0; $dy < $height; $dy++) {
            for ($dx = 0; $dx < $width; $dx++) { $this->pixel($x + $dx, $y + $dy, $color); }
        }
    }
    public function color(int $x, int $y): array
    {
        $x = max(0, min($this->width - 1, $x)); $y = max(0, min($this->height - 1, $y));
        $at = $x * 3; $row = $this->rows[$y];
        return [ord($row[$at]), ord($row[$at + 1]), ord($row[$at + 2])];
    }
    public function blend(int $x, int $y, array $color, float $opacity): void
    {
        $old = $this->color($x, $y); $opacity = max(0.0, min(1.0, $opacity));
        $this->pixel($x, $y, [(int) round($old[0] * (1 - $opacity) + $color[0] * $opacity), (int) round($old[1] * (1 - $opacity) + $color[1] * $opacity), (int) round($old[2] * (1 - $opacity) + $color[2] * $opacity)]);
    }
    public function transparent(array $color): void { $this->transparent = $color; }

    /** Limited decoder for the bundled bounded RGB/RGBA PNG collection. */
    public static function fromFile(string $path): self
    {
        if (is_link($path) || !is_file($path) || filesize($path) > 2097152) { throw new \RuntimeException('Invalid local CAPTCHA photo.'); }
        $raw = file_get_contents($path);
        if (!is_string($raw) || substr($raw, 0, 8) !== "\x89PNG\r\n\x1a\n") { return self::fromGd($path); }
        $offset = 8; $compressed = ''; $width = $height = $channels = 0;
        while ($offset + 12 <= strlen($raw)) {
            $length = unpack('N', substr($raw, $offset, 4))[1];
            if ($length > 2097152 || $offset + 12 + $length > strlen($raw)) { throw new \RuntimeException('Invalid CAPTCHA PNG chunk.'); }
            $kind = substr($raw, $offset + 4, 4); $data = substr($raw, $offset + 8, $length);
            if (!hash_equals(substr($raw, $offset + 8 + $length, 4), pack('N', crc32($kind . $data)))) { throw new \RuntimeException('Invalid CAPTCHA PNG checksum.'); }
            if ($offset === 8 && $kind !== 'IHDR') { throw new \RuntimeException('Invalid CAPTCHA PNG header.'); }
            if ($kind === 'IHDR') {
                if ($length !== 13) { throw new \RuntimeException('Invalid CAPTCHA PNG header.'); }
                $header = unpack('Nwidth/Nheight/Cdepth/Ctype/Ccompression/Cfilter/Cinterlace', $data);
                $width = $header['width']; $height = $header['height'];
                if ($width < 1 || $height < 1) { throw new \RuntimeException('Invalid CAPTCHA photo dimensions.'); }
                if ($width > 512 || $height > 512) { return self::fromGd($path); }
                if ($header['depth'] !== 8 || !in_array($header['type'], [2, 6], true) || $header['interlace'] !== 0 || $header['compression'] !== 0 || $header['filter'] !== 0) { return self::fromGd($path); }
                $channels = $header['type'] === 6 ? 4 : 3;
            } elseif ($kind === 'IDAT') { $compressed .= $data; }
            elseif ($kind === 'IEND') { break; }
            $offset += $length + 12;
        }
        if (!$channels || $compressed === '') { throw new \RuntimeException('Invalid CAPTCHA PNG data.'); }
        $stride = $width * $channels; $expected = ($stride + 1) * $height;
        $scan = @gzuncompress($compressed, $expected);
        if (!is_string($scan) || strlen($scan) !== $expected) { throw new \RuntimeException('Invalid CAPTCHA PNG pixels.'); }
        $image = new self($width, $height); $previous = str_repeat("\0", $stride);
        for ($y = 0; $y < $height; $y++) {
            $base = $y * ($stride + 1); $filter = ord($scan[$base]); $row = substr($scan, $base + 1, $stride);
            if ($filter > 4) { throw new \RuntimeException('Invalid CAPTCHA PNG filter.'); }
            if ($filter !== 0) {
                for ($i = 0; $i < $stride; $i++) {
                    $a = $i >= $channels ? ord($row[$i - $channels]) : 0; $b = ord($previous[$i]); $c = $i >= $channels ? ord($previous[$i - $channels]) : 0;
                    $predict = match ($filter) { 1 => $a, 2 => $b, 3 => (int) floor(($a + $b) / 2), 4 => self::paeth($a, $b, $c) };
                    $row[$i] = chr((ord($row[$i]) + $predict) & 255);
                }
            }
            if ($channels === 3) { $image->rows[$y] = $row; }
            else {
                $rgb = '';
                for ($x = 0; $x < $width; $x++) {
                    $at = $x * 4; $alpha = ord($row[$at + 3]) / 255;
                    for ($c = 0; $c < 3; $c++) { $rgb .= chr((int) round(ord($row[$at + $c]) * $alpha + 255 * (1 - $alpha))); }
                }
                $image->rows[$y] = $rgb;
            }
            $previous = $row;
        }
        return $image;
    }
    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c; $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c);
        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }
    private static function fromGd(string $path): self
    {
        if (!function_exists('imagecreatefromstring')) { throw new \RuntimeException('Upload the current PNG CAPTCHA photo collection. Custom formats or large images require PHP GD.'); }
        $info = @getimagesize($path);
        if (!is_array($info) || $info[0] > 2048 || $info[1] > 2048) { throw new \RuntimeException('CAPTCHA photo dimensions are too large.'); }
        $source = @imagecreatefromstring((string) file_get_contents($path));
        if ($source === false) { throw new \RuntimeException('Invalid local CAPTCHA photo.'); }
        try {
            $width = imagesx($source); $height = imagesy($source);
            if ($width > 2048 || $height > 2048) { throw new \RuntimeException('CAPTCHA photo dimensions are too large.'); }
            $target = imagecreatetruecolor(240, 240); imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255)); imagecopyresampled($target, $source, 0, 0, 0, 0, 240, 240, $width, $height);
            try {
                $image = new self(240, 240);
                for ($y = 0; $y < 240; $y++) { $row = ''; for ($x = 0; $x < 240; $x++) { $v = imagecolorat($target, $x, $y); $row .= pack('C3', ($v >> 16) & 255, ($v >> 8) & 255, $v & 255); } $image->rows[$y] = $row; }
                return $image;
            } finally { imagedestroy($target); }
        } finally { imagedestroy($source); }
    }
    /** Mild low-pass blur; sufficient shape contrast remains for visitors. */
    public function soften(float $strength = 0.35): self
    {
        $strength = max(0.0, min(0.6, $strength)); $image = new self($this->width, $this->height);
        for ($y = 0; $y < $this->height; $y++) {
            $row = '';
            for ($x = 0; $x < $this->width; $x++) {
                $center = $this->color($x, $y); $left = $this->color($x - 1, $y); $right = $this->color($x + 1, $y); $up = $this->color($x, $y - 1); $down = $this->color($x, $y + 1);
                for ($c = 0; $c < 3; $c++) { $row .= chr((int) round($center[$c] * (1 - $strength) + ($left[$c] + $right[$c] + $up[$c] + $down[$c]) * $strength / 4)); }
            }
            $image->rows[$y] = $row;
        }
        return $image;
    }
    public function wave(float $horizontal, float $vertical, float $phase): self
    {
        $image = new self($this->width, $this->height);
        for ($y = 0; $y < $this->height; $y++) {
            $row = ''; $shift = sin($y / 19 + $phase) * $horizontal;
            for ($x = 0; $x < $this->width; $x++) {
                $sx = max(0, min($this->width - 1, (int) round($x + $shift)));
                $sy = max(0, min($this->height - 1, (int) round($y + sin($x / 31 + $phase) * $vertical)));
                $row .= substr($this->rows[$sy], $sx * 3, 3);
            }
            $image->rows[$y] = $row;
        }
        return $image;
    }
    /** Separable box blur with edge clamping; bounded work independent of source size. */
    public function blur(int $radius = 2): self
    {
        $radius = max(1, min(3, $radius)); $window = 2 * $radius + 1;
        $horizontal = new self($this->width, $this->height);
        for ($y = 0; $y < $this->height; $y++) {
            $sum = [0, 0, 0]; $row = '';
            for ($i = -$radius; $i <= $radius; $i++) { $v = $this->color($i, $y); for ($c = 0; $c < 3; $c++) $sum[$c] += $v[$c]; }
            for ($x = 0; $x < $this->width; $x++) {
                $row .= pack('C3', (int) round($sum[0] / $window), (int) round($sum[1] / $window), (int) round($sum[2] / $window));
                $remove = $this->color($x - $radius, $y); $add = $this->color($x + $radius + 1, $y);
                for ($c = 0; $c < 3; $c++) $sum[$c] += $add[$c] - $remove[$c];
            }
            $horizontal->rows[$y] = $row;
        }
        $result = new self($this->width, $this->height); $sums = [];
        for ($x = 0; $x < $this->width; $x++) {
            $sums[$x] = [0, 0, 0];
            for ($i = -$radius; $i <= $radius; $i++) { $v = $horizontal->color($x, $i); for ($c = 0; $c < 3; $c++) $sums[$x][$c] += $v[$c]; }
        }
        for ($y = 0; $y < $this->height; $y++) {
            $row = '';
            for ($x = 0; $x < $this->width; $x++) {
                $sum = $sums[$x]; $row .= pack('C3', (int) round($sum[0] / $window), (int) round($sum[1] / $window), (int) round($sum[2] / $window));
                $remove = $horizontal->color($x, $y - $radius); $add = $horizontal->color($x, $y + $radius + 1);
                for ($c = 0; $c < 3; $c++) $sums[$x][$c] += $add[$c] - $remove[$c];
            }
            $result->rows[$y] = $row;
        }
        return $result;
    }
    public function crop(int $x, int $y, int $width, int $height): self
    {
        if ($x < 0 || $y < 0 || $x + $width > $this->width || $y + $height > $this->height) {
            throw new \InvalidArgumentException('Invalid crop.');
        }
        $result = new self($width, $height);
        for ($i = 0; $i < $height; $i++) { $result->rows[$i] = substr($this->rows[$y + $i], $x * 3, $width * 3); }
        return $result;
    }
    /** Copy a bounded raster into an image sheet; stock photos use direct row copies. */
    public function paste(self $source, int $x, int $y, int $width, int $height): void
    {
        if ($x < 0 || $y < 0 || $width < 1 || $height < 1 || $x + $width > $this->width || $y + $height > $this->height) {
            throw new \InvalidArgumentException('Invalid photo sheet bounds.');
        }
        if ($width === $source->width && $height === $source->height) {
            for ($row = 0; $row < $height; $row++) { $this->rows[$y + $row] = substr_replace($this->rows[$y + $row], $source->rows[$row], $x * 3, $width * 3); }
            return;
        }
        // Preserve the proportions of custom PNGs inside each square cell.
        $scale = min($width / $source->width, $height / $source->height);
        $fitWidth = max(1, (int) floor($source->width * $scale)); $fitHeight = max(1, (int) floor($source->height * $scale));
        $left = $x + (int) floor(($width - $fitWidth) / 2); $top = $y + (int) floor(($height - $fitHeight) / 2);
        for ($row = 0; $row < $fitHeight; $row++) {
            for ($column = 0; $column < $fitWidth; $column++) {
                $this->pixel($left + $column, $top + $row, $source->color((int) floor($column / $scale), (int) floor($row / $scale)));
            }
        }
    }

    public function bytes(): string
    {
        $scanlines = '';
        foreach ($this->rows as $row) { $scanlines .= "\0" . $row; }
        $compressed = gzcompress($scanlines, 6);
        if ($compressed === false) { throw new \RuntimeException('Image compression failed.'); }
        $transparent = $this->transparent === null ? '' : self::chunk('tRNS', pack('n3', ...$this->transparent));
        return "\x89PNG\r\n\x1a\n" . self::chunk('IHDR', pack('NNCCCCC', $this->width, $this->height, 8, 2, 0, 0, 0)) . $transparent . self::chunk('IDAT', $compressed) . self::chunk('IEND', '');
    }
    public function uri(): string { return 'data:image/png;base64,' . base64_encode($this->bytes()); }
    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}


/** Repeatable visual variation only; answers/identifiers still use random_int/random_bytes. */
final class JciCaptchaVisual
{
    private int $state;
    public function __construct(string $seed) { $this->state = ((int) hexdec(substr(hash('sha256', $seed), 0, 8)) & 0x7fffffff) ?: 1; }
    public function number(int $min, int $max): int
    {
        $this->state ^= $this->state << 13; $this->state &= 0x7fffffff;
        $this->state ^= $this->state >> 17; $this->state ^= $this->state << 5; $this->state &= 0x7fffffff;
        return $min + $this->state % ($max - $min + 1);
    }
    public function marks(JciCaptchaPng $image, int $curves = 3, float $opacity = 0.27): void
    {
        for ($i = 0; $i < $curves; $i++) {
            $offset = $this->number(8, $image->height - 8); $amplitude = $this->number(4, 13); $period = $this->number(22, 48); $phase = $this->number(0, 628) / 100;
            $color = [$this->number(55, 120), $this->number(70, 135), $this->number(95, 150)];
            for ($x = 0; $x < $image->width; $x++) {
                $y = (int) round($offset + sin($x / $period + $phase) * $amplitude);
                $image->blend($x, $y, $color, $opacity); $image->blend($x, $y + 1, $color, $opacity * 0.65);
            }
        }
        // Random symbols are rasterized into the image, not removable DOM/CSS layers.
        for ($i = 0; $i < 6; $i++) {
            $cx = $this->number(10, $image->width - 11); $cy = $this->number(10, $image->height - 11); $radius = $this->number(3, 7); $color = [65, 100, 135];
            if ($this->number(0, 1)) {
                for ($j = -$radius; $j <= $radius; $j++) { $image->blend($cx + $j, $cy, $color, $opacity); $image->blend($cx, $cy + $j, $color, $opacity); }
            } else {
                for ($a = 0; $a < 360; $a += 8) { $r = $a * M_PI / 180; $image->blend($cx + (int) round(cos($r) * $radius), $cy + (int) round(sin($r) * $radius), $color, $opacity); }
            }
        }
    }
    private static function photoRaster(array $asset, int $size = 0): JciCaptchaPng
    {
        $visual = new self((string) ($asset['visual_seed'] ?? hash('sha256', (string) $asset['path'])));
        $image = JciCaptchaPng::fromFile($asset['path']);
        if ($size > 0) {
            if ($size < 96 || $size > 240) { throw new \InvalidArgumentException('Invalid photo tile size.'); }
            $thumbnail = new JciCaptchaPng($size, $size, [248, 249, 252]);
            $thumbnail->paste($image, 0, 0, $size, $size);
            $image = $thumbnail;
        }
        $image = $image->wave($visual->number(10, 25) / 10, $visual->number(7, 18) / 10, $visual->number(0, 628) / 100)->blur(2);
        $visual->marks($image, 4, 0.26);
        return $image;
    }
    public static function photo(array $asset, int $size = 0): string { return self::photoRaster($asset, $size)->bytes(); }

    /** One protected response carries the nine independently marked photographs. */
    public static function sheet(array $assets): string
    {
        if (count($assets) !== 9) { throw new \InvalidArgumentException('A photo sheet needs nine tiles.'); }
        $size = 160; // Enough detail for the displayed tiles without rendering full-size sources.
        $sheet = new JciCaptchaPng(3 * $size, 3 * $size, [248, 249, 252]);
        foreach (array_values($assets) as $index => $asset) {
            $sheet->paste(self::photoRaster($asset, $size), ($index % 3) * $size, intdiv($index, 3) * $size, $size, $size);
        }
        return $sheet->bytes();
    }
}


final class JciCaptchaFactory
{
    private const DIGITS = [
        ['01110','10001','10011','10101','11001','10001','01110'],
        ['00100','01100','00100','00100','00100','00100','01110'],
        ['01110','10001','00001','00010','00100','01000','11111'],
        ['11110','00001','00001','01110','00001','00001','11110'],
        ['00010','00110','01010','10010','11111','00010','00010'],
        ['11111','10000','10000','11110','00001','00001','11110'],
        ['01110','10000','10000','11110','10001','10001','01110'],
        ['11111','00001','00010','00100','01000','01000','01000'],
        ['01110','10001','10001','01110','10001','10001','01110'],
        ['01110','10001','10001','01111','00001','00001','01110'],
    ];

    public static function numbers(): array
    {
        $answer = ''; $image = new JciCaptchaPng(240, 84);
        $visual = new JciCaptchaVisual(bin2hex(random_bytes(16)));
        $visual->marks($image, 3, 0.12);
        for ($i = 0; $i < 6; $i++) {
            $digit = random_int(0, 9); $answer .= (string) $digit;
            $left = 12 + 36 * $i + random_int(-3, 3); $top = 15 + random_int(-3, 3);
            $sx = random_int(45, 53) / 10; $sy = random_int(63, 71) / 10;
            $angle = random_int(-12, 12) * M_PI / 180; $shear = random_int(-8, 8) / 100;
            $cos = cos($angle); $sin = sin($angle);
            $color = [random_int(25, 70), random_int(35, 80), random_int(55, 100)];
            foreach (self::DIGITS[$digit] as $y => $row) {
                for ($x = 0; $x < 5; $x++) {
                    if ($row[$x] !== '1') continue;
                    for ($dy = 0; $dy < (int) ceil($sy); $dy++) {
                        for ($dx = 0; $dx < (int) ceil($sx); $dx++) {
                            $px = $x * $sx + $dx - 2.5 * $sx; $py = $y * $sy + $dy - 3.5 * $sy;
                            $image->pixel($left + (int) round($px * $cos - $py * $sin + $shear * $py + 2.5 * $sx), $top + (int) round($px * $sin + $py * $cos + 3.5 * $sy), $color);
                        }
                    }
                }
            }
        }
        $image = $image->wave(random_int(12, 24) / 10, random_int(8, 18) / 10, random_int(0, 628) / 100)->soften(0.18);
        $visual->marks($image, 2, 0.30);
        for ($i = 0; $i < 180; $i++) { $image->blend(random_int(0, 239), random_int(0, 83), [90, 110, 135], 0.22); }
        return ['answer' => $answer, 'presentation' => ['type' => 'numbers', 'image' => $image->uri()]];
    }

    private static function pieceMask(int $x, int $y, int $size, bool $flip): bool
    {
        $margin = 8; $radius = 7; $center = (int) floor($size / 2);
        $body = $x >= $margin && $x < $size - $margin && $y >= $margin && $y < $size - $margin;
        $tabY = $flip ? $size - $margin : $margin;
        $notchY = $flip ? $margin : $size - $margin;
        $tab = ($x - $center) ** 2 + ($y - $tabY) ** 2 <= $radius ** 2;
        $notch = ($x - $center) ** 2 + ($y - $notchY) ** 2 < $radius ** 2;
        return ($body || $tab) && !$notch;
    }

    public static function puzzle(): array
    {
        $width = 320; $height = 128; $size = 44;
        $image = new JciCaptchaPng($width, $height); $visual = new JciCaptchaVisual(bin2hex(random_bytes(16)));
        $bands = [];
        for ($i = 0; $i < 18; $i++) { $bands[] = [random_int(70, 210), random_int(70, 210), random_int(70, 210)]; }
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $band = $bands[((int) floor(($x + 18 * sin($y / 13)) / 24) + 18) % 18];
                $shade = random_int(-8, 8);
                $image->pixel($x, $y, array_map(static fn(int $v): int => max(0, min(255, $v + $shade)), $band));
            }
        }
        $visual->marks($image, 5, 0.22); $image = $image->soften(0.28);
        $left = random_int(60, 260); $top = random_int(18, 66); $flip = (bool) random_int(0, 1);
        $piece = new JciCaptchaPng($size, $size, [1, 0, 1]); $piece->transparent([1, 0, 1]);
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if (!self::pieceMask($x, $y, $size, $flip)) continue;
                $piece->pixel($x, $y, $image->color($left + $x, $top + $y));
                $edge = !self::pieceMask($x - 1, $y, $size, $flip) || !self::pieceMask($x + 1, $y, $size, $flip) || !self::pieceMask($x, $y - 1, $size, $flip) || !self::pieceMask($x, $y + 1, $size, $flip);
                $image->blend($left + $x, $top + $y, [35, 45, 65], $edge ? 0.70 : 0.46);
                if ($edge) $piece->blend($x, $y, [245, 248, 255], 0.60);
            }
        }
        // A differently shaped decoy prevents using a single solid rectangle as the answer.
        $decoyLeft = $left > 155 ? random_int(15, 70) : random_int(220, 270);
        for ($y = 0; $y < $size; $y++) { for ($x = 0; $x < $size; $x++) {
            if (self::pieceMask($x, $y, $size, !$flip)) $image->blend($decoyLeft + $x, $top + $y, [35, 45, 65], 0.40);
        } }
        return ['answer' => (string) $left, 'presentation' => ['type' => 'puzzle', 'image' => $image->uri(), 'piece' => $piece->uri(), 'top' => $top, 'width' => $width, 'height' => $height, 'size' => $size, 'maximum' => $width - $size]];
    }

    /** Assets are a local private manifest; category names are never file URLs. */
    public static function photo(array $categories): array
    {
        $valid = [];
        foreach ($categories as $category) {
            if (is_array($category) && is_string($category['label'] ?? null) && is_array($category['images'] ?? null) && count($category['images']) >= 2) { $valid[] = $category; }
        }
        if (count($valid) < 2) { throw new \RuntimeException('Install a local photo collection with at least two categories.'); }
        $target = random_int(0, count($valid) - 1); $tiles = []; $correct = []; $assets = []; $used = [];
        $count = random_int(2, 4);
        for ($i = 0; $i < 9; $i++) {
            $category = $target;
            if ($i >= $count) { do { $category = random_int(0, count($valid) - 1); } while ($category === $target); }
            $items = array_values(array_filter($valid[$category]['images'], static fn($path): bool => !is_string($path) || !isset($used[$path])));
            if (!$items) { $items = $valid[$category]['images']; }
            $image = $items[random_int(0, count($items) - 1)];
            if (!is_string($image) || is_link($image) || !is_file($image) || filesize($image) > 2097152) { throw new \RuntimeException('Invalid local photo asset.'); }
            $used[$image] = true;
            $info = @getimagesize($image);
            if (!is_array($info) || !in_array($info['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true)) { throw new \RuntimeException('Invalid local photo format.'); }
            $value = bin2hex(random_bytes(12));
            $tiles[] = ['value' => $value];
            $assets[$value] = ['path' => (string) realpath($image), 'mime' => $info['mime'], 'visual_seed' => bin2hex(random_bytes(16))];
            if ($category === $target) { $correct[] = $value; }
        }
        // Secure Fisher-Yates; no answer or category is attached to individual tiles.
        for ($i = count($tiles) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$tiles[$i], $tiles[$j]] = [$tiles[$j], $tiles[$i]]; }
        sort($correct, SORT_STRING);
        return ['answer' => implode(',', $correct), 'presentation' => ['type' => 'photo', 'category' => $valid[$target]['label'], 'tiles' => $tiles], 'assets' => $assets];
    }
}


/** HTTP adapter. Integrations retain control over content delivery and logging. */
final class JciCaptchaGate
{
    private JciCaptchaEngine $engine;
    private JciCaptchaStore $store;
    private string $agent;
    public function __construct(private array $config, private array $labels)
    {
        $this->agent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 2048);
        $this->store = new JciCaptchaStore((string) $config['storage']);
        $this->engine = new JciCaptchaEngine($this->store, (string) $config['secret'], (string) $config['scope'], (int) ($config['timeout'] ?? 60), (int) ($config['attempts'] ?? 3));
    }

    public function evaluate(array $context, ?array $submitted = null): array
    {
        $submitted ??= $_POST;
        if (isset($_GET['cp_image'], $_GET['cp_tile'])) { $this->servePhoto((string) $_GET['cp_image'], (string) $_GET['cp_tile']); }
        if (isset($_GET['cp_expire'], $_GET['cp_id'], $_GET['cp_csrf'])) {
            $submitted = ['cp_id' => $_GET['cp_id'], 'cp_csrf' => $_GET['cp_csrf'], 'cp_timeout' => '1'];
        }
        if (isset($submitted['cp_id']) || isset($submitted['cp_csrf'])) {
            $id = is_string($submitted['cp_id'] ?? null) ? $submitted['cp_id'] : '';
            $csrf = is_string($submitted['cp_csrf'] ?? null) ? $submitted['cp_csrf'] : '';
            $answer = $submitted['cp_answer'] ?? '';
            if (is_array($answer)) {
                if (count($answer) > 9 || array_filter($answer, static fn($v): bool => !is_string($v) || !preg_match('/^[a-f0-9]{24}$/D', $v))) { $answer = ''; }
                else { $answer = array_values(array_unique($answer)); sort($answer, SORT_STRING); $answer = implode(',', $answer); }
            }
            if (!is_string($answer)) { $answer = ''; }
            try { $result = $this->engine->verify($id, $csrf, $answer, $this->agent, !empty($submitted['cp_timeout']), null, $this->isAjax(), fn(): array => $this->newChallenge()); }
            catch (\InvalidArgumentException | \JsonException $e) { return ['status' => 'invalid', 'reason' => 'captcha_invalid_request']; }
            if ($result['status'] === 'submit') {
                $fields = [];
                $local = $GLOBALS['jci_lp_local_context'] ?? null;
                if (is_array($local) && ($local['continue'] ?? null) instanceof \Closure) {
                    $GLOBALS['captcha_pending_timeout'] = (int) ($this->config['timeout'] ?? 60);
                    try { $fields['_lp_continue'] = ($local['continue'])(); }
                    finally { unset($GLOBALS['captcha_pending_timeout']); }
                }
                $this->ajaxReply(['submit' => true, 'fields' => $fields]);
            }
            $this->removeTiles($id, (array) ($result['retired_assets'] ?? []));
            unset($result['retired_assets']);
            if ($result['status'] === 'solved' || $result['status'] === 'failed') {
                $this->remember($result['status']);
                $this->removeTiles($id, array_keys((array) ($result['context']['_captcha_assets'] ?? [])));
                if ($result['status'] === 'failed') { $this->recordEvidence((string) ($result['context']['request_id'] ?? $id), $result['reason'], $submitted); }
            } elseif ($result['status'] === 'pending') {
                $result['id'] = $id; $result['csrf'] = $csrf;
                if (!isset($result['presentation'])) {
                    $result['presentation'] = $this->store->update($id, static fn(array &$state): array => $state['presentation']);
                }
            }
            return $result;
        }
        $remember = $_COOKIE[$this->engine->cookieName()] ?? '';
        $remembered = is_string($remember) ? $this->engine->remembered($remember, $this->agent) : null;
        if ($remembered === 'failed' && !empty($this->config['remember_failure'])) {
            return ['status' => 'failed', 'reason' => 'captcha_remembered_failure', 'context' => $context];
        }
        if ($remembered === 'solved' && !empty($this->config['remember_success'])) {
            return ['status' => 'solved', 'reason' => 'captcha_remembered_success', 'context' => $context];
        }
        $bucket = hash_hmac('sha256', \JciVisitorIp::resolve($_SERVER)[1] . ':' . $this->config['scope'], $this->config['secret']);
        if (!$this->store->budget($bucket, (int) ($this->config['issue_limit'] ?? 100), 600)) {
            return ['status' => 'failed', 'reason' => 'captcha_rate_limit', 'context' => $context];
        }
        $type = (string) ($this->config['type'] ?? 'numbers');
        $challenge = $this->newChallenge();
        $context['_captcha_assets'] = $challenge['assets'] ?? [];
        $issued = $this->engine->issue($type, $challenge['answer'], $challenge['presentation'], $context, $this->agent);
        if (random_int(1, 100) === 1) { $this->store->collect(); }
        return ['status' => 'pending', 'reason' => 'captcha_required'] + $issued;
    }

    private function newChallenge(): array
    {
        return match ((string) ($this->config['type'] ?? 'numbers')) {
            'numbers' => JciCaptchaFactory::numbers(),
            'puzzle' => JciCaptchaFactory::puzzle(),
            'photo' => JciCaptchaFactory::photo($this->config['photos'] ?? []),
            default => throw new \InvalidArgumentException('Unknown CAPTCHA type.'),
        };
    }

    private function removeTiles(string $id, array $tiles): void
    {
        if (count($tiles) === 9) { $tiles[] = self::sheetKey($tiles); }
        foreach ($tiles as $tile) {
            if (!is_string($tile) || !preg_match('/^(?:[a-f0-9]{24}|sheet-[a-f0-9]{24})$/D', $tile)) continue;
            $path = rtrim($this->config['storage'], '/') . '/photo-' . hash('sha256', $id . ':' . $tile) . '.php';
            if (is_file($path) && !is_link($path)) @unlink($path);
        }
    }

    private static function sheetKey(array $tiles): string
    {
        sort($tiles, SORT_STRING);
        return 'sheet-' . substr(hash('sha256', implode(',', $tiles)), 0, 24);
    }

    private function isAjax(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['cp_ajax'] ?? '') === '1';
    }

    private function ajaxReply(array $body): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($body, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        exit;
    }

    private function challengeHtml(array $challenge, string $action): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $label = fn(string $key): string => $e((string) ($this->labels[$key] ?? $key));
        $p = $challenge['presentation'];
        $assetFields = isset($challenge['extra_fields']['_lp_continue']) ? ['_lp_asset'=>$challenge['extra_fields']['_lp_continue']] : [];
        $remaining = max(0, (int) $challenge['expires'] - time());
        $pw = max(1, (int) ($p['width'] ?? 280)); $ph = max(1, (int) ($p['height'] ?? 100));
        ob_start();
        if (($challenge['reason'] ?? '') === 'captcha_incorrect') { echo '<p class="cp-error" role="alert" tabindex="-1">' . $label('try_again') . '</p>'; }
        echo '<form method="post" action="' . $e($action) . '" id="cp-form"><input type="hidden" name="cp_scope" value="' . hash('sha256', $this->config['scope']) . '"><input type="hidden" name="cp_id" value="' . $e($challenge['id']) . '"><input type="hidden" name="cp_csrf" value="' . $e($challenge['csrf']) . '"><input type="hidden" name="cp_timeout" id="cp-timeout" value="0">';
        foreach (($challenge['extra_fields'] ?? []) as $key => $value) {
            if (is_string($key) && is_string($value)) { echo '<input type="hidden" name="' . $e($key) . '" value="' . $e($value) . '">'; }
        }
        if ($p['type'] === 'numbers') {
            echo '<label for="cp-answer">' . $label('numbers_instruction') . '</label><img class="cp-image" src="' . $e($p['image']) . '" alt="' . $label('numbers_image') . '"><input type="text" name="cp_answer" id="cp-answer" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" autocomplete="off" required autofocus>';
        } elseif ($p['type'] === 'puzzle') {
            echo '<label for="cp-answer">' . $label('puzzle_instruction') . '</label><div class="cp-puzzle"><img src="' . $e($p['image']) . '" alt="' . $label('puzzle_image') . '"><img class="cp-piece" id="cp-piece" src="' . $e($p['piece']) . '" alt="" data-top="' . ((int) $p['top'] / $ph * 100) . '" data-width="' . $pw . '"></div><input class="cp-slider" id="cp-answer" name="cp_answer" type="range" min="0" max="' . (int) ($p['maximum'] ?? 246) . '" value="0" step="1">';
        } else {
            echo '<p>' . $label('photo_instruction') . ' <strong>' . $e((string) $p['category']) . '</strong></p><div class="cp-grid" role="group" aria-describedby="cp-photo-error">';
            $separator = str_contains($action, '?') ? '&' : '?';
            $sheet = self::sheetKey(array_column($p['tiles'], 'value'));
            $url = $action . $separator . http_build_query(['cp_image' => $challenge['id'], 'cp_tile' => $sheet, 'cp_scope' => hash('sha256', $this->config['scope'])] + $assetFields);
            foreach ($p['tiles'] as $i => $tile) {
                echo '<label class="cp-tile"><input type="checkbox" name="cp_answer[]" value="' . $e($tile['value']) . '" aria-label="' . $label('select_image') . ' ' . ($i + 1) . '"><span class="cp-photo-frame"><img class="cp-photo-' . $i . '" src="' . $e($url) . '" width="480" height="480" decoding="async" alt=""></span></label>';
            }
            echo '</div><p id="cp-photo-error" class="cp-photo-error" role="alert" hidden>' . $label('photo_required') . '</p>';
        }
        echo '<p class="cp-count" role="timer"><span>' . $label('remaining_time') . '</span> <span id="cp-count">' . $remaining . '</span></p><button type="submit">' . $label('verify') . '</button></form>';
        return (string) ob_get_clean();
    }

    public function render(array $challenge, string $action = ''): never
    {
        if (headers_sent()) { throw new \RuntimeException('Place CAPTCHA protection before all page output.'); }
        if ($action === '') { $action = (string) ($_SERVER['REQUEST_URI'] ?? '/'); }
        if (!str_starts_with($action, '/') || str_starts_with($action, '//') || preg_match('/[\r\n]/', $action)) { throw new \RuntimeException('Invalid CAPTCHA form action.'); }
        if ($this->isAjax()) {
            $this->ajaxReply(['pending' => true, 'html' => $this->challengeHtml($challenge, $action), 'expires_in' => max(0, (int) $challenge['expires'] - time())]);
        }
        $nonce = base64_encode(random_bytes(18));
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, private, max-age=0');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        $ancestor = (string) ($this->config['frame_origin'] ?? '');
        $ancestor = preg_match('~^https?://[A-Za-z0-9.\[\]:_-]+$~D', $ancestor) ? $ancestor : "'self'";
        header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'nonce-$nonce'; script-src 'nonce-$nonce'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors $ancestor");
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $label = fn(string $key): string => $e((string) ($this->labels[$key] ?? $key));
        $p = $challenge['presentation'];
        $assetFields = isset($challenge['extra_fields']['_lp_continue']) ? ['_lp_asset'=>$challenge['extra_fields']['_lp_continue']] : [];
        $remaining = max(0, (int) $challenge['expires'] - time());
        $timeoutUrl = $action . (str_contains($action, '?') ? '&' : '?') . http_build_query(['cp_expire' => 1, 'cp_id' => $challenge['id'], 'cp_csrf' => $challenge['csrf'], 'cp_scope' => hash('sha256', $this->config['scope'])] + ($challenge['extra_fields'] ?? []));

        $rtl = !empty($this->config['rtl']);
        echo '<!doctype html><html lang="' . $e((string) ($this->config['language'] ?? 'en')) . '" dir="' . ($rtl ? 'rtl' : 'ltr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $label('challenge_title') . '</title><style nonce="' . $e($nonce) . '">';
        echo 'body{margin:0;background:#f4f6fa;color:#172238;font:16px system-ui,sans-serif;min-height:100vh;display:grid;place-items:center}.cp{box-sizing:border-box;width:min(94vw,440px);padding:28px;border:1px solid #dce2ed;border-radius:14px;background:white;box-shadow:0 10px 35px #192a4812}h1{font-size:22px;margin:0 0 16px}p{line-height:1.5}input[type=text]{box-sizing:border-box;width:100%;padding:12px;border:1px solid #9caac0;border-radius:6px;font:inherit;letter-spacing:4px;margin:12px 0}button{width:100%;padding:12px;border:0;border-radius:6px;background:#315bce;color:white;font:inherit;cursor:pointer}button:focus-visible,input:focus-visible{outline:3px solid #f3ad23;outline-offset:3px}.cp-image{display:block;max-width:100%;margin:12px auto}.cp-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:15px 0}.cp-tile{position:relative;border:2px solid #dce2ed;border-radius:6px;cursor:pointer;background:#eef1f8}.cp-tile:has(input:checked){border-color:#315bce;box-shadow:0 0 0 2px #315bce}.cp-tile img{width:100%;height:90px;object-fit:contain;display:block}.cp-tile input{position:absolute;top:3px;left:3px;width:20px;height:20px}.cp-puzzle{position:relative;width:280px;aspect-ratio:2.8;max-width:100%;margin:12px auto;direction:ltr}.cp-puzzle>img:first-child{width:100%;height:100%}.cp-piece{position:absolute;width:12.142857%;height:34%;box-shadow:0 0 0 1px white}.cp-slider{display:block;width:100%;direction:ltr;margin:18px 0}.cp-error,.cp-photo-error{color:#a21d27}.cp-count{font-size:14px;color:#516078}';
        echo self::loadingCss() . '.cp-grid[aria-invalid=true]{outline:2px solid #b42318;outline-offset:4px}.cp .cp-loading{position:static;inset:auto;z-index:auto;min-height:64px;background:transparent;padding:8px;gap:8px;flex-direction:row}.cp .cp-spinner{width:28px;height:28px;border-width:3px}.cp[aria-busy=true] button{cursor:wait;opacity:.7}';
        echo '.cp-photo-frame{position:relative;display:block;width:100%;aspect-ratio:1;overflow:hidden;background:#eef1f8;border-radius:4px}.cp-photo-frame img{position:absolute;display:block;width:300%;height:300%;max-width:none;object-fit:fill}.cp-tile input{z-index:1}.cp-js .cp-grid:not(.cp-photos-ready) .cp-photo-frame img,.cp-js .cp-grid:not(.cp-photos-ready) .cp-tile input{visibility:hidden}.cp-js .cp-grid:not(.cp-photos-ready) .cp-photo-frame{background:linear-gradient(100deg,#edf0f6 20%,#f9faff 45%,#edf0f6 70%);background-size:220% 100%;animation:cp-shimmer 1.3s ease-in-out infinite}@keyframes cp-shimmer{from{background-position:100% 0}to{background-position:-100% 0}}@media(prefers-reduced-motion:reduce){.cp-photo-frame{animation:none!important}}';
        for ($i = 0; $i < 9; $i++) { echo '.cp-photo-' . $i . '{left:-' . (($i % 3) * 100) . '%;top:-' . (intdiv($i, 3) * 100) . '%}'; }
        if ($p['type'] === 'puzzle') {
            $pw = max(1, (int) ($p['width'] ?? 280)); $ph = max(1, (int) ($p['height'] ?? 100)); $ps = max(1, (int) ($p['size'] ?? 34));
            echo '.cp-puzzle{width:' . $pw . 'px;aspect-ratio:' . $pw . '/' . $ph . '}.cp-piece{width:' . ($ps / $pw * 100) . '%;height:' . ($ps / $ph * 100) . '%;box-shadow:none}';
        }
        echo '</style><noscript><meta http-equiv="refresh" content="' . ($remaining + 1) . ';url=' . $e($timeoutUrl) . '"></noscript><script nonce="' . $e($nonce) . '">document.documentElement.classList.add("cp-js");</script></head><body><main class="cp"><h1>' . $label('challenge_title') . '</h1>' . self::loadingHtml((string) ($this->labels['loading'] ?? 'Loading…'));
        echo '<div id="cp-challenge">' . $this->challengeHtml($challenge, $action) . '</div>';
        if (!empty($this->config['learning']) && is_string($this->config['collector'] ?? null)) {
            echo '<input type="hidden" form="cp-form" name="cp_fp" id="cp-fingerprint" value="">';
            echo '<script nonce="' . $e($nonce) . '">(function(){' . $this->config['collector'] . '}());</script>';
        }
        $script = <<<'JAVASCRIPT'
(function () {
    "use strict";
    var container = document.getElementById("cp-challenge");
    var loading = document.getElementById("cp-loading");
    var panel = document.querySelector("main.cp");
    var form, clock, slider, piece, button, timer = null, submitting = false, generation = 0;
    var ajax = typeof fetch === "function" && typeof FormData === "function" && typeof URLSearchParams === "function";
    var deadline = Date.now() + __CP_SECONDS__ * 1000;
    function busy(value) {
        loading.hidden = !value;
        panel.setAttribute("aria-busy", value ? "true" : "false");
        button.disabled = value;
        form.querySelectorAll("input:not([type=hidden])").forEach(function (input) { input.disabled = value; });
    }
    function move() {
        if (piece) piece.style.left = (Number(slider.value) / (Number(piece.dataset.width) || 280) * 100) + "%";
    }
    function ready() {
        if (!submitting) {
            container.querySelectorAll(".cp-grid").forEach(function (grid) { grid.classList.add("cp-photos-ready"); });
            busy(false);
        }
    }
    function imagesReady() {
        var current = ++generation;
        var images = Array.from(container.querySelectorAll("img"));
        Promise.all(images.map(function (image) {
            return new Promise(function (resolve, reject) {
                function loaded() {
                    if (!image.naturalWidth) { reject(new Error("image")); return; }
                    if (typeof image.decode === "function") image.decode().then(resolve, reject);
                    else resolve();
                }
                if (image.complete) loaded();
                else { image.addEventListener("load", loaded, {once:true}); image.addEventListener("error", reject, {once:true}); }
            });
        })).then(function () { if (current === generation) ready(); })
            .catch(function () { if (current === generation && !submitting) showNetworkError(); });
    }
    function nativeSubmit(fields) {
        // The final verification uses ordinary page delivery, preserving redirects,
        // status codes, original POST data and the existing one-use continuations.
        Object.keys(fields || {}).forEach(function (key) {
            if (key !== "_lp_continue") return;
            var input = form.elements.namedItem(key);
            if (!input) { input = document.createElement("input"); input.type = "hidden"; input.name = key; form.appendChild(input); }
            input.value = fields[key];
        });
        form.querySelectorAll("input").forEach(function (input) { input.disabled = false; });
        form.noValidate = true;
        HTMLFormElement.prototype.submit.call(form);
    }
    function showNetworkError() {
        var error = container.querySelector(".cp-error");
        if (!error) { error = document.createElement("p"); error.className = "cp-error"; error.setAttribute("role", "alert"); container.prepend(error); }
        error.textContent = __CP_NETWORK__;
        // Do not resend an uncertain request and accidentally spend another attempt.
        clearTimeout(timer); busy(false); button.disabled = true;
        form.querySelectorAll("input:not([type=hidden])").forEach(function (input) { input.disabled = true; });
    }
    function send() {
        if (submitting) return;
        var body = new URLSearchParams(new FormData(form));
        body.set("cp_ajax", "1");
        submitting = true; clearTimeout(timer); busy(true);
        fetch(form.action, {method:"POST", credentials:"same-origin", cache:"no-store", redirect:"error", headers:{Accept:"application/json"}, body:body})
            .then(function (response) {
                if (!response.ok || !(response.headers.get("Content-Type") || "").includes("application/json")) throw new Error("verification");
                return response.json();
            }).then(function (response) {
                if (response.submit === true) { nativeSubmit(response.fields); return; }
                if (response.pending !== true || typeof response.html !== "string" || !Number.isFinite(response.expires_in)) throw new Error("verification");
                deadline = Math.min(deadline, Date.now() + Math.max(0, response.expires_in) * 1000);
                container.innerHTML = response.html;
                submitting = false; bind(); busy(true); imagesReady(); tick();
                var error = container.querySelector(".cp-error");
                if (error) error.focus();
                else if (slider && slider.type === "text") slider.focus();
            }).catch(showNetworkError);
    }
    function tick() {
        if (submitting) return;
        var left = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        clock.textContent = left;
        if (left === 0) {
            document.getElementById("cp-timeout").value = "1";
            form.noValidate = true;
            if (ajax) send();
            else { submitting = true; busy(true); nativeSubmit(); }
            return;
        }
        clearTimeout(timer); timer = setTimeout(tick, 250);
    }
    function validatePhotos() {
        var choices = form.querySelectorAll('input[name="cp_answer[]"]');
        if (!choices.length) return true;
        var valid = Array.from(choices).some(function (choice) { return choice.checked; });
        var error = form.querySelector("#cp-photo-error"), grid = form.querySelector(".cp-grid");
        error.hidden = valid;
        if (valid) grid.removeAttribute("aria-invalid");
        else { grid.setAttribute("aria-invalid", "true"); choices[0].focus(); }
        return valid;
    }
    function bind() {
        form = document.getElementById("cp-form"); clock = document.getElementById("cp-count");
        slider = document.getElementById("cp-answer"); piece = document.getElementById("cp-piece"); button = form.querySelector("button");
        if (piece) { piece.style.top = piece.dataset.top + "%"; slider.addEventListener("input", move); move(); }
        form.querySelectorAll('input[name="cp_answer[]"]').forEach(function (choice) {
            choice.addEventListener("change", function () {
                if (form.querySelector('input[name="cp_answer[]"]:checked')) {
                    form.querySelector("#cp-photo-error").hidden = true;
                    form.querySelector(".cp-grid").removeAttribute("aria-invalid");
                }
            });
        });
        form.addEventListener("submit", function (event) {
            if (submitting) { event.preventDefault(); return; }
            if (!validatePhotos()) { event.preventDefault(); return; }
            if (ajax) { event.preventDefault(); send(); }
            else { event.preventDefault(); submitting = true; clearTimeout(timer); busy(true); nativeSubmit(); }
        });
    }
    bind(); busy(true);
    if (typeof Promise === "function") imagesReady();
    else if (document.readyState === "complete") ready();
    else window.addEventListener("load", ready, {once:true});
    window.addEventListener("pageshow", function (event) {
        if (event.persisted) { submitting = false; busy(true); clearTimeout(timer); imagesReady(); tick(); }
    });
    tick();
}());
JAVASCRIPT;
        echo '<script nonce="' . $e($nonce) . '">' . str_replace(['__CP_SECONDS__', '__CP_NETWORK__'], [(string) $remaining, json_encode((string) ($this->labels['network_error'] ?? 'Verification could not be completed. Reload the page to try again.'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR)], $script) . '</script></main></body></html>';
        exit;
    }

    public static function loadingCss(): string
    {
        return '.cp-loading{display:none;position:fixed;inset:0;z-index:2147483647;background:#fff;color:#172238;align-items:center;justify-content:center;flex-direction:column;gap:18px;text-align:center;padding:24px;box-sizing:border-box;font:16px system-ui,sans-serif}.cp-js .cp-loading{display:flex}.cp-loading[hidden]{display:none!important}.cp-spinner{box-sizing:border-box;width:48px;height:48px;border:5px solid #d9e2f2;border-top-color:#405189;border-radius:50%;animation:cp-spin .8s linear infinite}@keyframes cp-spin{to{transform:rotate(360deg)}}';
    }

    public static function loadingHtml(string $label): string
    {
        return '<div class="cp-loading" id="cp-loading" role="status" aria-live="polite"><span class="cp-spinner" aria-hidden="true"></span><span>' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span></div>';
    }


    private function recordEvidence(string $id, string $reason, array $submitted): ?array
    {
        if (empty($this->config['learning'])) return null;
        try {
            $raw = $submitted['cp_fp'] ?? '';
            if (!is_string($raw) || strlen($raw) > 32768) return null;
            $fp = json_decode($raw, true, 16); $components = [];
            foreach (array_slice((array) ($fp['components'] ?? []), 0, 64, true) as $key=>$hash) {
                if (is_string($key) && strlen($key) <= 64 && is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash)) $components[$key] = $hash;
            }
            if (count($components) < 2) return null;
            ksort($components, SORT_STRING);
            $fp['components'] = $components;

            $row = ['event_id'=>$id, 'user_id'=>(string) ($this->config['user_id'] ?? ''), 'campaign_id'=>(int) ($this->config['campaign_id'] ?? 0), 'scope'=>$this->config['scope'], 'reason'=>$reason, 'observed_at'=>gmdate('c'), 'components'=>$components, 'fingerprint_hash'=>hash('sha256',json_encode($components, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 'weight'=>'evidence_only'];
            $body = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $envelope = ['body'=>$body,'signature'=>hash_hmac('sha256', 'evidence:' . $body, $this->config['secret'])];
            $path = rtrim($this->config['storage'],'/') . '/evidence-' . $id . '.php';
            $handle = @fopen($path, 'x'); if (!$handle) return null;
            try { @chmod($path,0600); fwrite($handle,"<?php exit; ?>\n" . json_encode($envelope,JSON_THROW_ON_ERROR)); } finally { fclose($handle); }
            if (function_exists('curl_init') && !empty($this->config['evidence_url'])) {
                $curl = curl_init($this->config['evidence_url']);
                curl_setopt_array($curl, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($envelope,JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>300,CURLOPT_TIMEOUT_MS=>800,CURLOPT_FOLLOWLOCATION=>false]);
                curl_exec($curl);
            }
            return $fp;
        } catch (\Throwable $error) { error_log('CAPTCHA evidence failed: ' . $error->getMessage()); return null; }
    }

    private function remember(string $status): void
    {
        $setting = $status === 'solved' ? 'success' : 'failure';
        if (empty($this->config['remember_' . $setting])) { return; }
        $days = (int) ($this->config[$setting . '_days'] ?? 1);
        if ($days < 1 || $days > 400) { throw new \RuntimeException('Invalid CAPTCHA cookie lifetime.'); }
        $https = !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        $seconds = $days * 86400;
        if (!setcookie($this->engine->cookieName(), $this->engine->remember($status, $this->agent, $seconds), ['expires' => time() + $seconds, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax'])) {
            throw new \RuntimeException('CAPTCHA cookie could not be set.');
        }
    }

    public function view(string $id, string $csrf): array { return $this->engine->view($id, $csrf, $this->agent); }

    private function photoBytes(string $id, string $tile, array $asset): string
    {
        $path = rtrim($this->config['storage'], '/') . '/photo-' . hash('sha256', $id . ':' . $tile) . '.php';
        if (is_link($path)) { throw new \RuntimeException('Invalid CAPTCHA image cache.'); }
        $handle = @fopen($path, 'c+b');
        if (!$handle) { throw new \RuntimeException('CAPTCHA image cache is unavailable.'); }
        try {
            if (!@chmod($path, 0600) || !flock($handle, LOCK_EX)) { throw new \RuntimeException('CAPTCHA image cache is unavailable.'); }
            $prefix = "<?php exit; ?>\n"; $raw = stream_get_contents($handle, 2097153);
            if (is_string($raw) && strlen($raw) <= 2097152 && str_starts_with($raw, $prefix . "\x89PNG\r\n\x1a\n")) { return substr($raw, strlen($prefix)); }
            $bytes = isset($asset['tiles']) ? JciCaptchaVisual::sheet($asset['tiles']) : JciCaptchaVisual::photo($asset); $raw = $prefix . $bytes;
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $raw) !== strlen($raw) || !fflush($handle)) { throw new \RuntimeException('CAPTCHA image cache could not be written.'); }
            return $bytes;
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function servePhoto(string $id, string $tile): never
    {
        $asset = null;
        if (preg_match('/^(?:[a-f0-9]{24}|sheet-[a-f0-9]{24})$/D', $tile)) {
            try {
                $asset = $this->store->update($id, function (array &$state) use ($tile): ?array {
                    if (($state['scope'] ?? '') !== $this->config['scope'] || $state['state'] !== 'pending' || $state['expires'] <= time() || !hash_equals((string) $state['ua'], hash('sha256', $this->agent))) { return null; }
                    if (str_starts_with($tile, 'sheet-')) {
                        $tiles = (array) ($state['presentation']['tiles'] ?? []);
                        if (count($tiles) !== 9 || !hash_equals(self::sheetKey(array_column($tiles, 'value')), $tile)) return null;
                        $assets = [];
                        foreach ($tiles as $item) {
                            $asset = $state['context']['_captcha_assets'][$item['value']] ?? null;
                            if (!is_array($asset)) return null;
                            $assets[] = $asset;
                        }
                        return ['tiles' => $assets];
                    }
                    return $state['context']['_captcha_assets'][$tile] ?? null;
                });
            } catch (\Throwable $e) { $asset = null; }
        }
        header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff');
        if (!is_array($asset)) { http_response_code(404); exit; }
        foreach ($asset['tiles'] ?? [$asset] as $image) {
            if (!is_string($image['path'] ?? null) || is_link($image['path']) || !is_file($image['path'])) { http_response_code(404); exit; }
        }
        try { $bytes = $this->photoBytes($id, $tile, $asset); }
        catch (\Throwable $error) { error_log('CAPTCHA image rendering failed: ' . $error->getMessage()); http_response_code(503); exit; }
        header('Content-Type: image/png'); echo $bytes; exit;
    }
}


final class JciCaptchaRuntime
{
    /** Returns only after success; the host PHP file then continues normally. */
    public static function protect(array $config, array $labels): void
    {
        try {
            if (headers_sent()) { throw new \RuntimeException('CAPTCHA code must run before output.'); }
            header('Cache-Control: no-store, private, max-age=0');
            if (strlen((string) ($_SERVER['QUERY_STRING'] ?? '')) > 16384 || strlen(json_encode($_POST, JSON_THROW_ON_ERROR)) > 98304 || $_FILES !== []) {
                self::fail($config, 'captcha_request_size');
            }
            $context = ['request' => ['get' => $_GET, 'post' => $_POST, 'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), 'query' => (string) ($_SERVER['QUERY_STRING'] ?? ''), 'referrer' => (string) ($_SERVER['HTTP_REFERER'] ?? '')]];
            if (function_exists('jciCaptchaWebGate')) {
                $result = \jciCaptchaWebGate($config, $labels, $context);
                if ($result['status'] === 'pending') { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'cp_challenge'=>true,'frame_url'=>$result['frame_url'],'expires_in'=>max(1,(int)$result['expires']-time())], JSON_THROW_ON_ERROR); exit; }
            } else {
                $gate = new JciCaptchaGate($config, $labels); $result = $gate->evaluate($context);
                if ($result['status'] === 'pending') { $gate->render($result); }
            }
            if ($result['status'] !== 'solved') { self::fail($config, (string) $result['reason']); }
            $request = $result['context']['request'] ?? null;
            if (!is_array($request)) { throw new \RuntimeException('CAPTCHA continuation is unavailable.'); }
            $_GET = $request['get']; $_POST = $request['post'];
            $_REQUEST = array_merge($_COOKIE, $_GET, $_POST);
            $_SERVER['REQUEST_METHOD'] = $request['method'];
            $_SERVER['QUERY_STRING'] = $request['query'];
            $_SERVER['HTTP_REFERER'] = (string) ($request['referrer'] ?? '');
        } catch (\Throwable $error) {
            error_log('CAPTCHA protection failed: ' . $error->getMessage());
            if (!headers_sent()) { http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store'); }
            exit('Service temporarily unavailable.');
        }
    }

    public static function fail(array $config, string $reason): never
    {
        self::logFailure($config, $reason);
        $action = (string) ($config['failure_action'] ?? '403');
        if (in_array($action, ['redirect', '301'], true)) {
            $url = (string) ($config['failure_url'] ?? '');
            $parts = parse_url($url);
            if (!preg_match('/[\r\n]/', $url) && is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])) {
                header('Cache-Control: no-store, private, max-age=0');
                header('Location: ' . $url, true, $action === '301' ? 301 : 302); exit;
            }
            $action = '403';
        }
        if ($action === 'blank') { http_response_code(204); exit; }
        $status = in_array($action, ['403', '404', '500'], true) ? (int) $action : 403;
        http_response_code($status); header('Content-Type: text/plain; charset=utf-8'); header('Cache-Control: no-store');
        exit(match ($status) { 404 => 'Not Found', 500 => 'Internal Server Error', default => 'Forbidden' });
    }

    public static function logFailure(array $config, string $reason): void
    {
        try {
            $zone = new \DateTimeZone((string) ($config['timezone'] ?? 'UTC'));
            $path = rtrim((string) $config['storage'], '/') . '/failures.php';
            if (is_link($path)) { throw new \RuntimeException('Invalid failure log.'); }
            $handle = @fopen($path, 'c+b');
            if ($handle === false) { throw new \RuntimeException('Failure log unavailable.'); }
            try {
                if (!@chmod($path, 0600) || !flock($handle, LOCK_EX)) { throw new \RuntimeException('Failure log unavailable.'); }
                $stats = fstat($handle);
                if ($stats['size'] === 0 && fwrite($handle, "<?php exit; ?>\n") !== 15) { throw new \RuntimeException('Failure log unavailable.'); }
                fseek($handle, 0, SEEK_END);
                if (fputcsv($handle, [(new \DateTimeImmutable('now', $zone))->format('Y-m-d H:i:s'), \JciVisitorIp::resolve($_SERVER)[1], substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 2048), $reason], ',', '"', '') === false || !fflush($handle)) {
                    throw new \RuntimeException('Failure log write failed.');
                }
            } finally { flock($handle, LOCK_UN); fclose($handle); }
        } catch (\Throwable $e) { error_log('CAPTCHA private log failed: ' . $e->getMessage()); }
    }
}

}
if (!function_exists('jciCaptchaProof')) {
function jciCaptchaBinding(array $payload): string
{
    unset($payload['captcha_proof']);
    $sort = static function (mixed $v) use (&$sort): mixed {
        if (!is_array($v)) { return $v; }
        if (!array_is_list($v)) { ksort($v, SORT_STRING); }
        foreach ($v as &$item) { $item = $sort($item); } unset($item);
        return $v;
    };
    return hash('sha256', json_encode($sort($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function jciCaptchaProof(array $payload, string $requestId, string $secret, string $status, string $reason): array
{
    $data = ['v' => 1, 'request_id' => $requestId, 'binding' => jciCaptchaBinding($payload), 'status' => $status, 'reason' => $reason, 'expires' => time() + 120];
    $data['signature'] = hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), $secret);
    return $data;
}

function jciCaptchaVerifyProof(array $payload, string $requestId, string $secret): string
{
    $proof = $payload['captcha_proof'] ?? null;
    if ($proof === null) { return 'missing'; }
    if (!is_array($proof) || strlen($secret) < 32) { return 'invalid'; }
    $keys = ['v','request_id','binding','status','reason','expires']; $data = [];
    foreach ($keys as $key) { if (!array_key_exists($key, $proof)) { return 'invalid'; } $data[$key] = $proof[$key]; }
    if ($data['v'] !== 1 || $data['request_id'] !== $requestId || !is_int($data['expires']) || $data['expires'] <= time() || $data['expires'] > time() + 125 || !in_array($data['status'], ['solved','failed'], true) || !in_array($data['reason'], ['captcha_solved','captcha_remembered_success','captcha_incorrect','captcha_timeout','captcha_attempt_limit','captcha_remembered_failure','captcha_rate_limit','captcha_invalid_request'], true) || !hash_equals(jciCaptchaBinding($payload), (string) $data['binding'])) { return 'invalid'; }
    return is_string($proof['signature'] ?? null) && hash_equals(hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), $secret), $proof['signature']) ? $data['status'] : 'invalid';
}


function jciCaptchaRuntimeConfig(array $config): array
{
    $config['storage'] = $config['storage'] ?? __DIR__ . '/.captcha-protection';
    if (($config['type'] ?? '') === 'photo') {
        $manifest = ($config['asset_root'] ?? __DIR__) . '/captcha-photos/manifest.php';
        if (!is_file($manifest)) { throw new RuntimeException('Upload the local CAPTCHA photo collection.'); }
        $categories = require $manifest;
        if (!is_array($categories)) { throw new RuntimeException('Invalid CAPTCHA photo collection.'); }
        foreach ($categories as &$category) { $category['label'] = (string) ($GLOBALS['captcha_labels'][$category['label']] ?? $category['label']); } unset($category);
        $config['photos'] = $categories;
    }
    return $config;
}

function jciCaptchaRequestScope(): string
{
    $v = $_POST['cp_scope'] ?? $_GET['cp_scope'] ?? '';
    return is_string($v) ? $v : '';
}

function jciCaptchaOriginalRequest(): array
{
    if ($_FILES !== [] || strlen(json_encode($_POST, JSON_THROW_ON_ERROR)) > 98304) { throw new RuntimeException('CAPTCHA requires a page request without file uploads.'); }
    return ['get' => $_GET, 'post' => $_POST, 'query' => (string) ($_SERVER['QUERY_STRING'] ?? ''), 'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), 'referrer' => (string) ($_SERVER['HTTP_REFERER'] ?? '')];
}

function jciCaptchaRestoreRequest(array $request): void
{
    $_GET = $request['get']; $_POST = $request['post']; $_REQUEST = array_merge($_COOKIE, $_GET, $_POST);
    $_SERVER['QUERY_STRING'] = $request['query']; $_SERVER['REQUEST_METHOD'] = $request['method'];
    $_SERVER['HTTP_REFERER'] = (string) ($request['referrer'] ?? '');
}

function jciCaptchaClientStart(): ?array
{
    $config = $GLOBALS['captcha_profiles']['filtering'] ?? [];
    if (empty($config['enabled']) || !hash_equals(hash('sha256', (string) $config['scope']), jciCaptchaRequestScope())) { return null; }
    $gate = new JciCaptchaGate(jciCaptchaRuntimeConfig($config), $GLOBALS['captcha_labels']);
    $result = $gate->evaluate([]);
    if ($result['status'] === 'pending') { $gate->render($result); }
    if (!in_array($result['status'], ['solved','failed'], true) || !isset($result['context']['payload'], $result['context']['request_id'])) { JciCaptchaRuntime::fail(jciCaptchaRuntimeConfig($config), 'captcha_invalid_request'); }
    jciCaptchaRestoreRequest($result['context']['request']);
    return $GLOBALS['captcha_resume'] = $result;
}

function jciCaptchaClientDecision(array $payload, string $requestId, callable $resolve): array
{
    $config = $GLOBALS['captcha_profiles']['filtering'] ?? [];
    if (empty($config['enabled'])) { return $resolve($payload, $requestId); }
    $resume = $GLOBALS['captcha_resume'] ?? null; unset($GLOBALS['captcha_resume']);
    if (is_array($resume)) {
        $payload = $resume['context']['payload']; $requestId = $resume['context']['request_id'];
        $payload['captcha_proof'] = jciCaptchaProof($payload, $requestId, $config['secret'], $resume['status'], $resume['reason']);
        return $resolve($payload, $requestId);
    }
    $decision = $resolve($payload, $requestId);
    if (empty($decision['captcha_required'])) { return $decision; }
    $gate = new JciCaptchaGate(jciCaptchaRuntimeConfig($config), $GLOBALS['captcha_labels']);
    $result = $gate->evaluate(['payload' => $payload, 'request_id' => $requestId, 'request' => jciCaptchaOriginalRequest()]);
    if ($result['status'] === 'pending') { $gate->render($result); }
    if (!in_array($result['status'], ['solved','failed'], true)) { JciCaptchaRuntime::fail(jciCaptchaRuntimeConfig($config), 'captcha_invalid_request'); }
    $payload['captcha_proof'] = jciCaptchaProof($payload, $requestId, $config['secret'], $result['status'], $result['reason']);
    return $resolve($payload, $requestId);
}

function jciCaptchaFrameTicket(array $challenge, array $config, string $parentOrigin): string
{
    $value = rtrim(strtr(base64_encode(json_encode(['id' => $challenge['id'], 'csrf' => $challenge['csrf'], 'scope' => $config['scope'], 'origin' => $parentOrigin, 'expires' => $challenge['expires']], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    return $value . '.' . hash_hmac('sha256', 'frame:' . $value, $config['secret']);
}

function jciCaptchaReadFrame(string $value, array $config): ?array
{
    if (strlen($value) > 4096) { return null; }
    $p = explode('.', $value);
    if (count($p) !== 2 || !hash_equals(hash_hmac('sha256', 'frame:' . $p[0], $config['secret']), $p[1])) { return null; }
    $raw = base64_decode(strtr($p[0], '-_', '+/'), true); $v = is_string($raw) ? json_decode($raw, true, 8) : null;
    return is_array($v) && ($v['scope'] ?? '') === $config['scope'] && ($v['expires'] ?? 0) + 120 >= time() ? $v : null;
}

function jciCaptchaFrameReply(array $body, string $origin): never
{
    if (!empty($body['cp_blocked']) && in_array($body['status'] ?? 0, [403,404,500], true)) http_response_code($body['status']);
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
    $nonce = base64_encode(random_bytes(18));
    if (preg_match('~^https?://[A-Za-z0-9.\[\]:_-]+$~D', $origin)) header("Content-Security-Policy: default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; frame-ancestors $origin; base-uri 'none'");
    $data = json_encode(['captcha_complete' => true, 'body' => $body], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    $target = json_encode($origin, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html class="cp-js"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style nonce="' . $e($nonce) . '">' . JciCaptchaGate::loadingCss() . '</style></head><body>' . JciCaptchaGate::loadingHtml((string) ($GLOBALS['captcha_labels']['loading'] ?? 'Loading…')) . '<script nonce="' . $e($nonce) . '">parent.postMessage(' . $data . ',' . $target . ');</script></body></html>'; exit;
}

function jciCaptchaRelayConfig(): array
{
    $cfg = $GLOBALS['captcha_profiles']['filtering'] ?? [];
    $cfg['as_json'] = true; $cfg['mode'] = 'filtering';
    return $cfg;
}

function jciCaptchaRelayStart(): void
{
    $cfg = jciCaptchaRelayConfig();
    if (empty($cfg['enabled'])) return;
    $isFrame = isset($_GET['cp_frame']) || isset($_GET['cp_image']);
    $raw = (string) file_get_contents('php://input');
    $input = strlen($raw) <= 8192 ? json_decode($raw, true) : null;
    $authorization = is_array($input) && is_string($input['cp_authorization'] ?? null) ? $input : null;
    if (!$isFrame && $authorization === null) return;
    try {
        if ($authorization !== null) {
            $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
            $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
            if ($host === '' || !hash_equals(strtolower((string) parse_url('https://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)), $host)) { throw new RuntimeException('Invalid verification origin.'); }
        }
        $result = jciCaptchaWebGate($cfg, $GLOBALS['captcha_labels'], [], $authorization ?? $_POST);
        if (!in_array($result['status'], ['solved','failed'], true) || !isset($result['context']['payload'], $result['context']['request_id'])) { throw new RuntimeException('Invalid verification request.'); }
        $payload = $result['context']['payload']; $id = $result['context']['request_id'];
        $payload['captcha_proof'] = jciCaptchaProof($payload, $id, $cfg['secret'], $result['status'], $result['reason']);
        $decision = jciCaptchaResolveRelay($payload, $id);
        jciApplyCookie($decision['cookie'] ?? null); jciApplyCookie($decision['lander_cookie'] ?? null);
        unset($decision['captcha_required'], $decision['request_id']);
        jciJson($decision);
    } catch (Throwable $error) { error_log('CAPTCHA relay verification failed: ' . $error->getMessage()); http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); exit('Forbidden'); }
}
function jciCaptchaRelayDecision(array $payload, string $requestId, array $decision): array
{
    $cfg = jciCaptchaRelayConfig();
    if (empty($cfg['enabled']) || empty($decision['captcha_required'])) return $decision;
    // CAPTCHA cookies are issued by this relay; require the JS page on the same origin.
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $relayOrigin = (string) ($cfg['website_origin'] ?? ($scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($origin === '' || !hash_equals(strtolower($relayOrigin), strtolower($origin)) || !hash_equals(strtolower((string) parse_url($origin, PHP_URL_HOST)), strtolower((string) parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)))) { throw new RuntimeException('Install the PHP code on the same origin as the JavaScript page for CAPTCHA.'); }
    $result = jciCaptchaWebGate($cfg, $GLOBALS['captcha_labels'], ['payload'=>$payload,'request_id'=>$requestId,'request'=>jciCaptchaOriginalRequest()]);
    if ($result['status'] === 'pending') { jciJson(['ok'=>true,'cp_challenge'=>true,'frame_url'=>$result['frame_url'],'expires_in'=>max(1,(int)$result['expires']-time())]); }
    if (!in_array($result['status'], ['solved','failed'], true)) { throw new RuntimeException('Invalid verification request.'); }
    $payload['captcha_proof'] = jciCaptchaProof($payload, $requestId, $cfg['secret'], $result['status'], $result['reason']);
    return jciCaptchaResolveRelay($payload, $requestId);
}


function jciCaptchaWebGate(array $config, array $labels, array $context, ?array $submitted = null): array
{
    $config = jciCaptchaRuntimeConfig($config); $submitted ??= $_POST;
    $store = new JciCaptchaStore($config['storage']);
    if (is_string($submitted['cp_authorization'] ?? null)) {
        return $store->update($submitted['cp_authorization'], static function (array &$state) use ($config): array {
            if (($state['kind'] ?? '') !== 'authorization' || ($state['scope'] ?? '') !== $config['scope'] || ($state['state'] ?? '') !== 'pending' || ($state['expires'] ?? 0) <= time() || !hash_equals((string) ($state['ua'] ?? ''), hash('sha256', substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 2048)))) { throw new RuntimeException('CAPTCHA continuation is unavailable.'); }
            $state['state'] = 'consumed';
            return ['status'=>$state['status'],'reason'=>$state['reason'],'context'=>$state['context']];
        });
    }
    $frameValue = $_GET['cp_frame'] ?? null;
    $frame = is_string($frameValue) ? jciCaptchaReadFrame($frameValue, $config) : null;
    if ($frameValue !== null && $frame === null) { JciCaptchaRuntime::fail($config, 'captcha_invalid_request'); }
    if ($frame !== null) { $config['frame_origin'] = $frame['origin']; }
    $gate = new JciCaptchaGate($config, $labels);
    if ($frame !== null && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && !isset($_GET['cp_expire']) && !isset($_GET['cp_image'])) {
        if ((int) $frame['expires'] > time()) { $gate->render($gate->view($frame['id'], $frame['csrf'])); }
        $submitted = ['cp_id'=>$frame['id'],'cp_csrf'=>$frame['csrf'],'cp_timeout'=>'1'];
    }
    $result = $gate->evaluate($context, $submitted);
    if ($result['status'] === 'pending') {
        if ($frame === null && (!empty($config['as_json']) || isset($_GET['_cp_nor']))) {
            $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
            if ($origin === '') {
                $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
                $origin = (string) ($config['website_origin'] ?? ($scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? '')));
            }
            if (!preg_match('~^https?://[A-Za-z0-9.\[\]:_-]+$~D', $origin)) { throw new RuntimeException('A page origin is required for CAPTCHA.'); }
            $ticket = jciCaptchaFrameTicket($result, $config, $origin);
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            $result['frame_url'] = $uri . (str_contains($uri,'?')?'&':'?') . http_build_query(['cp_frame'=>$ticket,'cp_scope'=>hash('sha256',$config['scope'])]);
            return $result;
        }
        $local = $GLOBALS['jci_lp_local_context'] ?? null;
        if (is_array($local) && ($local['continue'] ?? null) instanceof Closure) {
            $GLOBALS['captcha_pending_timeout'] = (int) ($config['timeout'] ?? 60);
            $result['extra_fields']['_lp_continue'] = ($local['continue'])();
            unset($GLOBALS['captcha_pending_timeout']);
        }
        $gate->render($result);
    }
    if ($frame !== null) {
        if (!in_array($result['status'], ['solved','failed'], true)) { $result=['status'=>'failed','reason'=>'captcha_invalid_request','context'=>[]]; }
        if ($result['status'] === 'failed' && ($config['mode'] ?? '') !== 'filtering') {
            if (($config['mode'] ?? '') === 'landing' && function_exists('jciLpLog')) {
                jciLpLog('blocked', $result['reason'], (string) ($result['context']['lp']['token'] ?? ''));
                if (!empty($GLOBALS['jciLp']['block_cookie_enabled'])) { jciLpTagBlockedVisitor($GLOBALS['jciLp']); }
            } else { JciCaptchaRuntime::logFailure($config, $result['reason']); }
            $action=(string)($config['failure_action']??'403');
            jciCaptchaFrameReply(['cp_blocked'=>true,'action'=>$action,'status'=>in_array($action,['403','404','500'],true)?(int)$action:($action==='blank'?204:403),'url'=>$action==='redirect'?(string)($config['failure_url']??''):null],$frame['origin']);
        }
        $id = $store->create(['kind'=>'authorization','state'=>'pending','scope'=>$config['scope'],'ua'=>hash('sha256',substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,2048)),'expires'=>time()+120,'status'=>$result['status'],'reason'=>$result['reason'],'context'=>$result['context']??[]]);
        jciCaptchaFrameReply(['cp_authorization'=>$id],$frame['origin']);
    }
    return $result;
}

}
$GLOBALS['captcha_profiles'] = array (
  'version' => 1,
  'filtering' => 
  array (
    'enabled' => false,
    'type' => 'numbers',
    'timeout' => 60,
    'attempts' => 3,
    'remember_success' => true,
    'success_days' => 1,
    'remember_failure' => true,
    'failure_days' => 1,
    'learning' => false,
    'failure_action' => '403',
    'failure_url' => '',
    'secret' => 'e29d84be7b986481e110cad0aaec415f86803048bf6764d94b989ac0a557e876',
    'scope' => 'campaign:dn8ew2sucw3gq83hbqimk9twh:526319:filtering',
    'mode' => 'filtering',
    'rtl' => false,
    'language' => 'en',
    'timezone' => 'Asia/Kolkata',
    'website_origin' => 'https://chick-delights-app-24zor.ondigitalocean.app',
  ),
  'landing' => 
  array (
    'enabled' => true,
    'type' => 'numbers',
    'timeout' => 60,
    'attempts' => 3,
    'remember_success' => true,
    'success_days' => 1,
    'remember_failure' => true,
    'failure_days' => 1,
    'learning' => false,
    'failure_action' => '403',
    'failure_url' => '',
    'secret' => 'e29d84be7b986481e110cad0aaec415f86803048bf6764d94b989ac0a557e876',
    'scope' => 'campaign:dn8ew2sucw3gq83hbqimk9twh:526319:landing',
    'mode' => 'landing',
    'rtl' => false,
    'language' => 'en',
    'timezone' => 'Asia/Kolkata',
    'website_origin' => 'https://chick-delights-app-24zor.ondigitalocean.app',
  ),
  'standalone' => 
  array (
    'enabled' => false,
    'type' => 'numbers',
    'timeout' => 60,
    'attempts' => 3,
    'remember_success' => true,
    'success_days' => 1,
    'remember_failure' => true,
    'failure_days' => 1,
    'learning' => false,
    'failure_action' => '403',
    'failure_url' => '',
    'secret' => 'e29d84be7b986481e110cad0aaec415f86803048bf6764d94b989ac0a557e876',
    'scope' => 'campaign:dn8ew2sucw3gq83hbqimk9twh:526319:standalone',
    'mode' => 'standalone',
    'rtl' => false,
    'language' => 'en',
    'timezone' => 'Asia/Kolkata',
    'website_origin' => 'https://chick-delights-app-24zor.ondigitalocean.app',
  ),
);
foreach ($GLOBALS['captcha_profiles'] as &$cpProfile) { if (!is_array($cpProfile)) continue; $cpProfile['storage'] = __DIR__ . '/.captcha-protection'; $cpProfile['asset_root'] = __DIR__; } unset($cpProfile);
$GLOBALS['captcha_labels'] = array (
  'loading' => 'Loading…',
  'challenge_title' => 'Verify you are human',
  'numbers_instruction' => 'Enter the six numbers shown',
  'numbers_image' => 'CAPTCHA numbers',
  'puzzle_instruction' => 'Move the slider until the piece fits the gap',
  'puzzle_image' => 'Puzzle',
  'photo_instruction' => 'Select all images showing:',
  'select_image' => 'Select image',
  'photo_required' => 'Please select at least one photo before verifying.',
  'challenge_image' => 'Image',
  'remaining_time' => 'Seconds remaining:',
  'verify' => 'Verify',
  'try_again' => 'Incorrect answer. Please try again.',
  'shirts' => 'shirts',
  'chairs' => 'chairs',
  'cups' => 'cups',
  'shoes' => 'shoes',
  'backpacks' => 'backpacks',
  'bicycles' => 'bicycles',
  'cars' => 'cars',
  'umbrellas' => 'umbrellas',
  'clocks' => 'clocks',
  'apples' => 'apples',
  'guitars' => 'guitars',
  'dogs' => 'dogs',
  'network_error' => 'Verification could not be completed. Reload the page to try again.',
);


final class JciTrackerRejectedException extends RuntimeException
{
    private int $responseStatus;

    public function __construct(string $message, int $responseStatus)
    {
        parent::__construct($message);
        $this->responseStatus = $responseStatus;
    }

    public function responseStatus(): int
    {
        return $this->responseStatus;
    }
}

final class JciTrackerClient
{
    private const MAX_RESPONSE_BYTES = 65536;
    private const MAX_PROXY_BYTES = 10485760;
    private const MAX_PROXY_REDIRECTS = 5;

    private string $endpoint;
    private string $fallbackEndpoint;
    private int $connectTimeoutMs;
    private int $primaryTimeoutMs;
    private int $fallbackTimeoutMs;
    private int $campaignId;
    private string $userId;
    private string $localRoot;
    private bool $continueHostPage = false;

    public function __construct(
        string $endpoint,
        int $campaignId,
        string $userId,
        string $localRoot,
        ?string $fallbackEndpoint = null,
        int $connectTimeoutMs = 1500,
        int $primaryTimeoutMs = 4000,
        int $fallbackTimeoutMs = 6000
    ) {
        if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid tracker endpoint.');
        }

        $fallbackEndpoint = trim((string) (
            $fallbackEndpoint ?? JCI_TRACKER_FALLBACK_ENDPOINT
        ));

        if (!filter_var($fallbackEndpoint, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException(
                'Invalid offline tracker endpoint.'
            );
        }

        if (hash_equals($endpoint, $fallbackEndpoint)) {
            throw new InvalidArgumentException(
                'Primary and offline tracker endpoints must be different.'
            );
        }

        if ($campaignId < 1) {
            throw new InvalidArgumentException('Invalid campaign ID.');
        }

        if (!preg_match('/^[A-Za-z0-9]{25}$/D', $userId)) {
            throw new InvalidArgumentException('Invalid tracker user ID.');
        }

        $this->endpoint = $endpoint;
        $this->fallbackEndpoint = $fallbackEndpoint;
        $this->connectTimeoutMs = max(250, min(10000, $connectTimeoutMs));
        $this->primaryTimeoutMs = max(
            $this->connectTimeoutMs,
            min(30000, $primaryTimeoutMs)
        );
        $this->fallbackTimeoutMs = max(
            $this->connectTimeoutMs,
            min(30000, $fallbackTimeoutMs)
        );
        $this->campaignId = $campaignId;
        $this->userId = $userId;
        $this->localRoot = rtrim($localRoot, DIRECTORY_SEPARATOR);
    }

    private function failoverLogContext(): string
    {
        $username = (string) preg_replace(
            '/[\x00-\x1F\x7F]/',
            '',
            (string) JCI_TRACKER_USERNAME
        );
        $username = substr($username, 0, 64);

        return ' [username=' . ($username !== '' ? $username : 'unknown') .
            ', campaign_id=' . $this->campaignId . ']';
    }

    public function handle(): void
    {
        $this->sendNoCacheHeaders();
        
        if ($this->resumeLocalContinuation()) { exit; }
        $captchaResume = function_exists('jciCaptchaClientStart') ? jciCaptchaClientStart() : null;


        $this->run();

        if ($this->continueHostPage) {
            return;
        }

        exit;
    }

    public function run(): void
    {
        $this->sendNoCacheHeaders();

        try {
            $requestId = bin2hex(random_bytes(16));
            $payload = $this->buildPayload();
            $decision = $this->requestDecision($payload, $requestId);
            $this->applyResponseCookie($decision['cookie'] ?? null);
            $this->applyResponseCookie($decision['lander_cookie'] ?? null);
            $this->executeDecision($decision);
        } catch (JciTrackerRejectedException $exception) {
            error_log(
                'JCI tracker rejected the request: ' .
                $exception->getMessage()
            );

            if (!headers_sent()) {
                $statusCode = $exception->responseStatus();
                http_response_code(
                    $statusCode >= 400 && $statusCode <= 599
                        ? $statusCode
                        : 403
                );
                header('Content-Type: text/plain; charset=utf-8');
            }

            exit($exception->getMessage());
        } catch (Throwable $exception) {
            error_log('JCI tracker client failed: ' . $exception->getMessage());

            if (!headers_sent()) {
                http_response_code(503);
                header('Content-Type: text/plain; charset=utf-8');
                header('Cache-Control: no-store');
            }

            exit('Service temporarily unavailable.');
        }
    }

    private function sendNoCacheHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header(
            'Cache-Control: no-store, no-cache, must-revalidate, ' .
            'proxy-revalidate, max-age=0, s-maxage=0'
        );
        header('CDN-Cache-Control: no-store');
        header('Cloudflare-CDN-Cache-Control: no-store');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    private function buildPayload(): array
    {
        [$peerIp, $visitorIp] = $this->resolveRequestIps();
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');

        return [
            'date' => date('Y-m-d H:i:s'),
            'lan' => (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''),
            'ref' => (string) ($_SERVER['HTTP_REFERER'] ?? ''),
            'ip' => $peerIp,
            'ipr' => $visitorIp,
            
            'sn' => (string) ($_SERVER['SERVER_NAME'] ?? ''),
            'requestUri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'query' => $query,
            'qu' => $query,
            'ua' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'co' => (string) ($_COOKIE['_event'] ?? ''),
            'user_id' => $this->userId,
            'id' => $this->campaignId,

            /* Optional signals populated by a browser-side companion script. */
            'tz' => (string) ($_COOKIE['_jci_tz'] ?? ''),
            'imm' => (string) ($_COOKIE['_jci_move'] ?? ''),
            'spe' => (string) ($_COOKIE['_jci_spe'] ?? ''),
            'cam' => (string) ($_COOKIE['_jci_cam'] ?? ''),
            'mic' => (string) ($_COOKIE['_jci_mic'] ?? ''),
            'mou' => (string) ($_COOKIE['_jci_mou'] ?? ''),
            'he' => $this->normalizedRequestHeaders()
        ];
    }

    private function requestDecision(array $payload, string $requestId): array
    {
        return function_exists('jciCaptchaClientDecision')
            ? jciCaptchaClientDecision($payload, $requestId, fn(array $p, string $r): array => $this->requestDecisionRaw($p, $r))
            : $this->requestDecisionRaw($payload, $requestId);
    }

    private function requestDecisionRaw(array $payload, string $requestId): array
    {
        $encodedPayload = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_THROW_ON_ERROR
        );
        $attempts = [
            [
                'endpoint' => $this->endpoint,
                'timeout_ms' => $this->primaryTimeoutMs,
                'name' => 'primary'
            ],
            [
                'endpoint' => $this->fallbackEndpoint,
                'timeout_ms' => $this->fallbackTimeoutMs,
                'name' => 'offline-fallback'
            ]
        ];

        foreach ($attempts as $attemptIndex => $attempt) {
            try {
                $response = $this->requestEndpoint(
                    (string) $attempt['endpoint'],
                    $encodedPayload,
                    $requestId,
                    (int) $attempt['timeout_ms'],
                    (string) $attempt['name']
                );
            } catch (RuntimeException $exception) {
                if ($attemptIndex === 0) {
                    error_log(
                        'JCI primary tracker unavailable; using offline ' .
                        'snapshot' . $this->failoverLogContext() . ': ' .
                        $exception->getMessage()
                    );
                    continue;
                }

                throw $exception;
            }

            $statusCode = $response['status_code'];
            $responseBody = $response['body'];

            try {
                $decision = json_decode(
                    $responseBody,
                    true,
                    64,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException $exception) {
                if ($attemptIndex === 0) {
                    error_log(
                        'JCI primary tracker returned invalid JSON; using ' .
                        'offline snapshot' . $this->failoverLogContext() . '.'
                    );
                    continue;
                }

                throw new RuntimeException(
                    'Offline tracker returned invalid JSON.'
                );
            }

            if (!is_array($decision)) {
                if ($attemptIndex === 0) {
                    error_log(
                        'JCI primary tracker returned an invalid response; ' .
                        'using offline snapshot' .
                        $this->failoverLogContext() . '.'
                    );
                    continue;
                }

                throw new RuntimeException(
                    'Offline tracker returned an invalid response.'
                );
            }

            if (
                $attemptIndex === 0 &&
                in_array(
                    $statusCode,
                    [408, 425, 429, 500, 502, 503, 504],
                    true
                )
            ) {
                error_log(
                    'JCI primary tracker returned HTTP ' . $statusCode .
                    '; using offline snapshot' .
                    $this->failoverLogContext() . '.'
                );
                continue;
            }

            if (
                $statusCode < 200 ||
                $statusCode >= 300 ||
                ($decision['ok'] ?? false) !== true
            ) {
                throw new JciTrackerRejectedException(
                    (string) (
                        $decision['error'] ?? 'Tracker rejected the request.'
                    ),
                    $statusCode
                );
            }

            if (!is_bool($decision['is_user'] ?? null)) {
                if ($attemptIndex === 0) {
                    error_log(
                        'JCI primary tracker response has no decision; using ' .
                        'offline snapshot' . $this->failoverLogContext() . '.'
                    );
                    continue;
                }

                throw new RuntimeException('Tracker decision is missing.');
            }

            return $decision;
        }

        throw new RuntimeException('No tracker endpoint returned a decision.');
    }

    /** @return array{status_code: int, body: string} */
    private function requestEndpoint(
        string $endpoint,
        string $encodedPayload,
        string $requestId,
        int $timeoutMs,
        string $attemptName
    ): array {
        $responseBody = '';
        $curl = curl_init();

        if ($curl === false) {
            throw new RuntimeException('Unable to initialize cURL.');
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $encodedPayload,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => $this->connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Cache-Control: no-store, no-cache, max-age=0',
                'Pragma: no-cache',
                'X-JCI-Campaign-ID: ' . $this->campaignId,
                'X-JCI-User-ID: ' . $this->userId,
                'X-JCI-Request-ID: ' . $requestId,
                'X-JCI-Client-Attempt: ' . $attemptName
            ],
            CURLOPT_WRITEFUNCTION => static function (
                $curlHandle,
                string $chunk
            ) use (&$responseBody): int {
                if (
                    strlen($responseBody) + strlen($chunk) >
                    self::MAX_RESPONSE_BYTES
                ) {
                    return 0;
                }

                $responseBody .= $chunk;
                return strlen($chunk);
            }
        ]);

        $result = curl_exec($curl);
        $curlError = curl_error($curl);
        $curlErrorNumber = curl_errno($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if ($result === false) {
            throw new RuntimeException(
                'Tracker request failed (' . $curlErrorNumber . '): ' .
                $curlError
            );
        }

        if ($responseBody === '') {
            throw new RuntimeException('Tracker returned an empty response.');
        }

        return ['status_code' => $statusCode, 'body' => $responseBody];
    }

    private ?array $localLanderDecision = null;
    private bool $localLanderResumed = false;

    private function localContinuationDirectory(): string
    {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/jci-lp-' .
            hash('sha256', __FILE__ . '|' . $this->localRoot . '|' . $this->userId . '|' . $this->campaignId);
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory))) {
            throw new RuntimeException('No Redirect continuation storage is unavailable.');
        }
        $ownerProbe = tmpfile();
        if ($ownerProbe === false) { throw new RuntimeException('Unable to verify continuation storage ownership.'); }
        $owner = fstat($ownerProbe)['uid'];
        fclose($ownerProbe);
        if (fileowner($directory) !== $owner) {
            throw new RuntimeException('No Redirect continuation storage ownership is invalid.');
        }
        if (!@chmod($directory, 0700)) { throw new RuntimeException('Unable to protect continuation storage.'); }
        return $directory;
    }

    private function localRequestBinding(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/'); $parts = parse_url($uri); parse_str($parts['query'] ?? '', $query);
        foreach (array_keys($query) as $key) { if (str_starts_with($key, 'cp_') || $key === '_lp_continue' || $key === '_lp_asset') unset($query[$key]); }
        $uri = ($parts['path'] ?? '/') . ($query ? '?' . http_build_query($query) : '');
        // Use the same visitor identity as the tracker/LP checks. A proxy's
        // connection address may change between the page, images and form POSTs.
        [, $visitorIp] = $this->resolveRequestIps();
        return hash('sha256', json_encode([
            $visitorIp,
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            (string) ($_SERVER['HTTP_HOST'] ?? ''),
            $uri
        ], JSON_THROW_ON_ERROR));
    }



    private function saveLocalContinuation(): string
    {
        if ($this->localLanderDecision === null) { throw new RuntimeException('Missing local tracking decision.'); }
        $directory = $this->localContinuationDirectory();
        $ticket = bin2hex(random_bytes(32));
        $data = json_encode(['expires' => time() + max(90, (int) ($GLOBALS['captcha_pending_timeout'] ?? 0) + 120), 'binding' => $this->localRequestBinding(),

            'decision' => $this->localLanderDecision], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $path = $directory . '/' . $ticket;
        $handle = @fopen($path, 'x');
        if ($handle === false) { throw new RuntimeException('Unable to save No Redirect continuation.'); }
        try {
            if (!@chmod($path, 0600) || fwrite($handle, $data) !== strlen($data)) {
                @unlink($path);
                throw new RuntimeException('Unable to write No Redirect continuation.');
            }
        } finally { fclose($handle); }
        if (random_int(1, 20) === 1) {
            $checked = 0;
            foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $old) {
                if (++$checked > 100) break;
                if ($old->isFile() && $old->getMTime() < time() - 4200) @unlink($old->getPathname());
            }
        }
        return $ticket;
    }

    private function resumeLocalContinuation(): bool
    {
        $assetRead = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['cp_image'], $_GET['_lp_asset']);
        if (!$assetRead && !array_key_exists('_lp_continue', $_POST) && !(isset($_GET['cp_expire']) && array_key_exists('_lp_continue', $_GET))) { return false; }
        try {
            $ticket = $_POST['_lp_continue'] ?? $_GET['_lp_continue'] ?? $_GET['_lp_asset'];
            if ((!$assetRead && !in_array(($_SERVER['REQUEST_METHOD'] ?? ''), isset($_GET['cp_expire']) ? ['GET','POST'] : ['POST'], true)) || !is_string($ticket) || !preg_match('/^[a-f0-9]{64}$/D', $ticket)) {
                throw new RuntimeException('Invalid continuation.');
            }
            $path = $this->localContinuationDirectory() . '/' . $ticket;
            $handle = @fopen($path, 'r');
            if ($handle === false) { throw new RuntimeException('Continuation expired or already used.'); }
            try {
                if (!flock($handle, LOCK_EX)) { throw new RuntimeException('Unable to lock continuation.'); }
                $raw = stream_get_contents($handle, 131073);
                if (!is_string($raw) || strlen($raw) > 131072) { throw new RuntimeException('Invalid continuation data.'); }
                $record = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($record) || !is_array($record['decision'] ?? null) || ($record['decision']['request_type'] ?? '') !== 'nor') {
                    throw new RuntimeException('Invalid No Redirect continuation data.');
                }
                if ((int) ($record['expires'] ?? 0) < time()) { throw new RuntimeException('No Redirect continuation expired.'); }
                $bindingMatches = hash_equals((string) ($record['binding'] ?? ''), $this->localRequestBinding());

                if (!$bindingMatches) {
                    throw new RuntimeException('No Redirect continuation visitor or URL binding mismatch.');
                }
                if (!$assetRead && !@unlink($path)) { throw new RuntimeException('No Redirect continuation was already consumed.'); }
            } finally { flock($handle, LOCK_UN); fclose($handle); }
            $this->localLanderDecision = $record['decision'];
            $this->localLanderResumed = true;
            $this->executeDecision($this->localLanderDecision);
        } catch (Throwable $error) {
            error_log('JCI local verification continuation failed: ' . $error->getMessage());
            http_response_code(403);
            exit('No Redirect verification expired or is unavailable.');
        }
        return true;
    }

    private function executeDecision(array $decision): void
    {
        $requestType = (string) ($decision['request_type'] ?? '');
        $url = isset($decision['url']) && is_string($decision['url'])
            ? trim($decision['url'])
            : '';
        $statusTypes = [
            '400', '401', '403', '404', '405', '408',
            '410', '429', '500', '502', '503'
        ];

        /*
         * No safe/bot URL means the campaign intentionally leaves the host
         * page unchanged. request_type_main has no meaning without a URL and
         * must therefore be ignored, including HTTP status-code methods.
         */
        if ($url === '') {
            $this->continueHostPage = true;
            return;
        }

        if (in_array($requestType, $statusTypes, true)) {
            http_response_code((int) $requestType);
            header('Content-Type: text/plain; charset=utf-8');
            exit;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Tracker returned an invalid URL.');
        }

        switch ($requestType) {
            case 'nor':
                $this->localLanderDecision = $decision;
                $this->requireLocalTarget($url);
                return;

            case '301':
            case '302':
            case '307':
            case '308':
                header('Location: ' . $url, true, (int) $requestType);
                exit;

            case 'crf':
                header('Location: ' . $url, true, 302);
                exit;

            case 'mre':
                $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                header('Content-Type: text/html; charset=utf-8');
                echo '<!doctype html><html><head><meta charset="utf-8">' .
                    '<meta http-equiv="refresh" content="0;url=' .
                    $escapedUrl . '"></head><body></body></html>';
                exit;

            case 'brf':
                $escapedUrl = htmlspecialchars(
                    $url,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                );
                $jsonUrl = json_encode(
                    $url,
                    JSON_HEX_TAG | JSON_HEX_AMP |
                    JSON_HEX_APOS | JSON_HEX_QUOT |
                    JSON_UNESCAPED_SLASHES
                );
                header('Content-Type: text/html; charset=utf-8');
                header('Referrer-Policy: no-referrer');
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
                echo '<!doctype html><html><head><meta charset="utf-8">' .
                    '<meta name="referrer" content="no-referrer">' .
                    '<meta http-equiv="refresh" content="0;url=' .
                    $escapedUrl . '"></head><body><script>' .
                    'window.location.replace(' . $jsonUrl . ');' .
                    '</script><noscript><a rel="noreferrer" href="' .
                    $escapedUrl . '">Continue</a></noscript></body></html>';
                exit;

            case 'jslh':
            case 'jslr':
                $method = $requestType === 'jslr' ? 'replace' : 'assign';
                header('Content-Type: text/html; charset=utf-8');
                echo '<!doctype html><html><head><meta charset="utf-8">' .
                    '</head><body><script>window.location.' . $method . '(' .
                    json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP) .
                    ');</script></body></html>';
                exit;

            case 'ifr':
                $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
                header('Content-Type: text/html; charset=utf-8');
                echo '<!doctype html><html><head><meta charset="utf-8">' .
                    '<meta name="viewport" content="width=device-width,' .
                    'initial-scale=1"><style>html,body,iframe{border:0;margin:0;' .
                    'padding:0;width:100%;height:100%;overflow:hidden}</style>' .
                    '</head><body><iframe src="' . $escapedUrl .
                    '" referrerpolicy="no-referrer-when-downgrade"></iframe>' .
                    '</body></html>';
                exit;

            case 'proxy':
                $this->proxyPublicUrl($url);
                return;

            default:
                throw new RuntimeException('Unsupported tracker response type.');
        }
    }

    private function requireLocalTarget(string $url): void
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower(rtrim((string) ($parts['host'] ?? ''), '.')) : '';
        $requestHost = strtolower(rtrim((string) parse_url('http://' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST), '.'));
        if ($host === '' || $requestHost === '' || !hash_equals($requestHost, $host)) {
            throw new RuntimeException('Local target must use the current website hostname.');
        }
        $decodedPath = rawurldecode((string) ($parts['path'] ?? ''));
        if ($decodedPath === '' || $decodedPath[0] !== '/' ||
            preg_match('/[\x00-\x1f\x7f]/', $decodedPath) || str_contains($decodedPath, "\\")) {
            throw new RuntimeException('Invalid local target path.');
        }
        $segments = explode('/', ltrim($decodedPath, '/'));
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                throw new RuntimeException('Invalid local target path.');
            }
        }
        $localRoot = realpath($this->localRoot);
        $documentRootValue = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
        $documentRoot = $documentRootValue !== '' ? realpath($documentRootValue) : false;
        if ($localRoot === false || !is_dir($localRoot)) {
            throw new RuntimeException('Local website root is unavailable.');
        }
        // Map the complete URL path from the site's document root, not its basename.
        if ($documentRoot !== false && is_dir($documentRoot) &&
            ($localRoot === $documentRoot || str_starts_with($localRoot, rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
            $root = $documentRoot;
            $relativePath = ltrim($decodedPath, '/');
        } else {
            // Alias installations without a usable document root: map only a known
            // entry-script directory to its corresponding URL directory.
            $entry = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
            $scriptPath = rawurldecode((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
            $basePath = rtrim(str_replace('\\', '/', dirname($scriptPath)), '/.');
            if ($entry === false || !is_file($entry) || dirname($entry) !== $localRoot || $scriptPath === '' || $scriptPath[0] !== '/' ||
                ($basePath !== '' && !str_starts_with($decodedPath, $basePath . '/'))) {
                throw new RuntimeException('Local target URL cannot be mapped to this website.');
            }
            $root = $localRoot;
            $relativePath = ltrim(substr($decodedPath, strlen($basePath)), '/');
        }
        $target = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        if ($target === false || !is_file($target) || !is_readable($target) ||
            !str_starts_with($target, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Local target is unavailable.');
        }

        $extension = strtolower((string) pathinfo(
            $target,
            PATHINFO_EXTENSION
        ));
        $blockedExtensions = [
            'bak', 'backup', 'conf', 'config', 'env', 'inc', 'ini', 'key',
            'log', 'old', 'pem', 'pht', 'phtml', 'phar', 'phps', 'sql'
        ];

        if (in_array($extension, $blockedExtensions, true)) {
            throw new RuntimeException('Local target type is not public.');
        }

        if ($extension === 'php') {
            $previousContext = $GLOBALS['jci_lp_local_context'] ?? null;
            $GLOBALS['jci_lp_local_context'] = [
                'target_url' => $url,
                'cookie' => $this->localLanderDecision['lander_cookie'] ?? null,
                'resumed' => $this->localLanderResumed,
                'continue' => fn (): string => $this->saveLocalContinuation()
            ];
            try { require_once $target; }
            finally {
                if ($previousContext === null) unset($GLOBALS['jci_lp_local_context']);
                else $GLOBALS['jci_lp_local_context'] = $previousContext;
            }
            return;
        }

        if ($extension === 'js' || $extension === 'mjs') {
            $script = file_get_contents($target);

            if ($script === false) {
                throw new RuntimeException('Local target could not be read.');
            }

            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html><head><meta charset="utf-8">' .
                '<meta name="viewport" content="width=device-width,' .
                'initial-scale=1"></head><body><script' .
                ($extension === 'mjs' ? ' type="module"' : '') . '>' .
                str_ireplace('</script', '<\\/script', $script) .
                '</script></body></html>';
            return;
        }

        if ($extension === 'css') {
            $stylesheet = file_get_contents($target);

            if ($stylesheet === false) {
                throw new RuntimeException('Local target could not be read.');
            }

            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html><head><meta charset="utf-8">' .
                '<meta name="viewport" content="width=device-width,' .
                'initial-scale=1"><style>' .
                str_ireplace('</style', '<\\/style', $stylesheet) .
                '</style></head><body></body></html>';
            return;
        }

        $contentTypes = [
            'avif' => 'image/avif',
            'bmp' => 'image/bmp',
            'csv' => 'text/csv; charset=utf-8',
            'gif' => 'image/gif',
            'htm' => 'text/html; charset=utf-8',
            'html' => 'text/html; charset=utf-8',
            'ico' => 'image/x-icon',
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'json' => 'application/json; charset=utf-8',
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
            'ogg' => 'audio/ogg',
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'txt' => 'text/plain; charset=utf-8',
            'webm' => 'video/webm',
            'webp' => 'image/webp',
            'xml' => 'application/xml; charset=utf-8'
        ];
        $contentType = $contentTypes[$extension] ?? null;

        if ($contentType === null && function_exists('mime_content_type')) {
            $detectedType = mime_content_type($target);
            $contentType = is_string($detectedType) && $detectedType !== ''
                ? $detectedType
                : null;
        }

        header('Content-Type: ' . ($contentType ?? 'application/octet-stream'));
        header('X-Content-Type-Options: nosniff');

        if (readfile($target) === false) {
            throw new RuntimeException('Local target could not be read.');
        }
    }

    private function proxyPublicUrl(string $url): void
    {
        $currentUrl = $url;
        $response = [];

        for ($redirectCount = 0; ; $redirectCount++) {
            $response = $this->fetchProxyHop($currentUrl);
            $status = (int) $response['status'];

            if ($status < 300 || $status >= 400) {
                break;
            }

            $location = (string) $response['location'];

            if ($location === '') {
                throw new RuntimeException(
                    'The proxy target returned a redirect without a location.'
                );
            }

            if ($redirectCount >= self::MAX_PROXY_REDIRECTS) {
                throw new RuntimeException(
                    'The proxy target exceeded the redirect limit.'
                );
            }

            $currentUrl = $this->resolveProxyRedirectUrl(
                $currentUrl,
                $location
            );
        }

        $status = (int) $response['status'];
        $contentType = (string) $response['content_type'];
        $body = (string) $response['body'];
        $parts = (array) $response['parts'];
        $scheme = (string) $parts['scheme'];
        $host = (string) $parts['host'];
        $port = (int) $response['port'];

        http_response_code($status > 0 ? $status : 502);
        header(
            'Content-Type: ' .
            ($contentType !== '' ? $contentType : 'application/octet-stream')
        );

        if (preg_match('/^text\/html\b/i', $contentType)) {
            $baseUrl = $scheme . '://' . $this->urlHost($host) .
                (isset($parts['port']) ? ':' . $port : '') .
                rtrim(dirname((string) ($parts['path'] ?? '/')), '/') . '/';
            $baseTag = '<base href="' .
                htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') . '">';

            if (preg_match('/<head\b[^>]*>/i', $body)) {
                $body = preg_replace(
                    '/(<head\b[^>]*>)/i',
                    '$1' . $baseTag,
                    $body,
                    1
                ) ?? $body;
            } else {
                $body = $baseTag . $body;
            }
        }

        header('Content-Length: ' . strlen($body));
        echo $body;
    }

    private function fetchProxyHop(string $url): array
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            throw new RuntimeException('Invalid proxy target.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (
            !in_array($scheme, ['http', 'https'], true) ||
            $host === '' ||
            isset($parts['user']) ||
            isset($parts['pass'])
        ) {
            throw new RuntimeException('Invalid proxy target.');
        }

        $addresses = $this->resolvePublicHost($host);

        if ($addresses === []) {
            throw new RuntimeException('Proxy target is not publicly routable.');
        }

        $port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : 80);

        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Invalid proxy target port.');
        }

        $resolvedAddress = $addresses[0];
        $resolveValue = str_contains($resolvedAddress, ':')
            ? '[' . $resolvedAddress . ']'
            : $resolvedAddress;
        $body = '';
        $location = '';
        $responseTooLarge = false;
        $curl = curl_init();

        if ($curl === false) {
            throw new RuntimeException('Unable to initialize proxy request.');
        }

        $forwardHeaders = ['Cache-Control: no-cache'];

        foreach ([
            'HTTP_ACCEPT' => 'Accept',
            'HTTP_ACCEPT_LANGUAGE' => 'Accept-Language',
            'HTTP_COOKIE' => 'Cookie',
            'HTTP_REFERER' => 'Referer'
        ] as $serverKey => $headerName) {
            if (isset($_SERVER[$serverKey]) && is_scalar($_SERVER[$serverKey])) {
                $forwardHeaders[] =
                    $headerName . ': ' . (string) $_SERVER[$serverKey];
            }
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => (string) (
                $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0'
            ),
            CURLOPT_HTTPHEADER => $forwardHeaders,
            CURLOPT_RESOLVE => [
                $host . ':' . $port . ':' . $resolveValue
            ],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function (
                $curlHandle,
                string $headerLine
            ) use (&$location): int {
                if (preg_match('/^HTTP\//i', $headerLine)) {
                    $location = '';
                } elseif (stripos($headerLine, 'Location:') === 0) {
                    $location = trim(substr($headerLine, 9));
                }

                return strlen($headerLine);
            },
            CURLOPT_WRITEFUNCTION => static function (
                $curlHandle,
                string $chunk
            ) use (&$body, &$responseTooLarge): int {
                if (
                    strlen($body) + strlen($chunk) >
                    self::MAX_PROXY_BYTES
                ) {
                    $responseTooLarge = true;
                    return 0;
                }

                $body .= $chunk;
                return strlen($chunk);
            }
        ]);

        $result = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);

        if ($result === false) {
            if ($responseTooLarge) {
                throw new RuntimeException(
                    'The proxy target response exceeded the size limit.'
                );
            }

            throw new RuntimeException('Proxy request failed: ' . $error);
        }

        return [
            'status' => $status,
            'content_type' => $contentType,
            'body' => $body,
            'location' => $location,
            'parts' => $parts,
            'port' => $port
        ];
    }

    private function resolveProxyRedirectUrl(
        string $currentUrl,
        string $location
    ): string {
        $location = trim($location);

        if ($location === '' || preg_match('/[\x00-\x1F\x7F]/', $location)) {
            throw new RuntimeException('The proxy target returned an invalid redirect.');
        }

        $location = preg_replace('/#.*$/', '', $location) ?? '';

        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $locationScheme = parse_url($location, PHP_URL_SCHEME);

        if (is_string($locationScheme) && $locationScheme !== '') {
            throw new RuntimeException(
                'The proxy target redirected to an unsupported protocol.'
            );
        }

        $parts = parse_url($currentUrl);

        if (!is_array($parts)) {
            throw new RuntimeException('Unable to resolve the proxy redirect.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if ($scheme === '' || $host === '') {
            throw new RuntimeException('Unable to resolve the proxy redirect.');
        }

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        $authority = $scheme . '://' . $this->urlHost($host) .
            (isset($parts['port']) ? ':' . (int) $parts['port'] : '');

        if (str_starts_with($location, '?')) {
            return $authority . (string) ($parts['path'] ?? '/') . $location;
        }

        $path = str_starts_with($location, '/')
            ? $location
            : rtrim(dirname((string) ($parts['path'] ?? '/')), '/') . '/' .
                $location;
        $pathParts = explode('/', $path);
        $normalizedParts = [];

        foreach ($pathParts as $pathPart) {
            if ($pathPart === '' || $pathPart === '.') {
                continue;
            }

            if ($pathPart === '..') {
                array_pop($normalizedParts);
                continue;
            }

            $normalizedParts[] = $pathPart;
        }

        return $authority . '/' . implode('/', $normalizedParts);
    }

    private function urlHost(string $host): string
    {
        return str_contains($host, ':') ? '[' . $host . ']' : $host;
    }

    private function applyResponseCookie(mixed $cookie): void
    {
        if (!is_array($cookie)) {
            return;
        }

        $name = (string) ($cookie['name'] ?? '');
        $value = (string) ($cookie['value'] ?? '');

        if (
            !preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $name) ||
            $value === '' ||
            strlen($value) > 3800
        ) {
            return;
        }

        $lifetimeDays = max(
            0,
            (int) ($cookie['lifetime_days'] ?? 0)
        );

        $sameSite = (string) ($cookie['same_site'] ?? 'Lax');

        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            $sameSite = 'Lax';
        }

        setcookie($name, $value, [
            'expires' => $lifetimeDays > 0
                ? time() + ($lifetimeDays * 86400)
                : 0,
            'path' => '/',
            'secure' => true,
            'httponly' => (bool) ($cookie['http_only'] ?? true),
            'samesite' => $sameSite
        ]);
    }

    private function normalizedRequestHeaders(): string
    {
        $headers = [];

        $excludedHeaders = [
            'authorization',
            'cookie',
            'proxy-authorization',
            'x-jci-campaign-id',
            'x-jci-request-id',
            'x-jci-user-id'
        ];

        foreach ($_SERVER as $key => $value) {
            if (
                (!str_starts_with($key, 'HTTP_') &&
                 !in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) ||
                !is_scalar($value)
            ) {
                continue;
            }

            $name = strtolower(
                str_replace('_', '-', preg_replace('/^HTTP_/', '', $key))
            );

            if (in_array($name, $excludedHeaders, true)) {
                continue;
            }

            $headers[$name] = substr((string) $value, 0, 2048);
        }

        return json_encode(
            $headers,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        ) ?: '';
    }

    private function resolveRequestIps(): array
    {
        $ips = JciVisitorIp::resolve($_SERVER);
        if ($ips[0] === '') throw new RuntimeException('REMOTE_ADDR is unavailable.');
        return $ips;
    }

    private function isPublicIp(string $ip): bool
    {
        return JciVisitorIp::isPublic($ip);
    }

    private function resolvePublicHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host) ? [$host] : [];
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];

        if (is_array($records)) {
            foreach ($records as $record) {
                $address = (string) ($record['ip'] ?? $record['ipv6'] ?? '');

                if ($address !== '' && $this->isPublicIp($address)) {
                    $addresses[$address] = true;
                }
            }
        }

        return array_keys($addresses);
    }

}

if (!defined('JCI_TRACKER_CLIENT_NO_AUTORUN')) {
    (new JciTrackerClient(
        JCI_TRACKER_ENDPOINT,
        JCI_CAMPAIGN_ID,
        JCI_USER_ID,
        __DIR__,
        JCI_TRACKER_FALLBACK_ENDPOINT,
        JCI_TRACKER_CONNECT_TIMEOUT_MS,
        JCI_TRACKER_PRIMARY_TIMEOUT_MS,
        JCI_TRACKER_FALLBACK_TIMEOUT_MS
    ))->handle();
}
?>
