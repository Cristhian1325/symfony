<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Cache\Traits;

use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\Exception\InvalidArgumentException;
use Symfony\Component\Cache\Marshaller\PhpMarshaller;

/**
 * @author Titouan Galopin <galopintitouan@gmail.com>
 * @author Nicolas Grekas <p@tchwork.com>
 *
 * @internal
 */
trait PhpArrayTrait
{
    use ProxyTrait;

    private $file;
    private $keys;
    private $values;

    /**
     * Store an array of cached values.
     *
     * @param array $values The cached values
     */
    public function warmUp(array $values)
    {
        if (file_exists($this->file)) {
            if (!is_file($this->file)) {
                throw new InvalidArgumentException(sprintf('Cache path exists and is not a file: %s.', $this->file));
            }

            if (!is_writable($this->file)) {
                throw new InvalidArgumentException(sprintf('Cache file is not writable: %s.', $this->file));
            }
        } else {
            $directory = \dirname($this->file);

            if (!is_dir($directory) && !@mkdir($directory, 0777, true)) {
                throw new InvalidArgumentException(sprintf('Cache directory does not exist and cannot be created: %s.', $directory));
            }

            if (!is_writable($directory)) {
                throw new InvalidArgumentException(sprintf('Cache directory is not writable: %s.', $directory));
            }
        }

        $dumpedValues = '';
        $dumpedMap = array();
        $dump = <<<'EOF'
<?php

// This file has been auto-generated by the Symfony Cache Component.

return array(array(


EOF;

        foreach ($values as $key => $value) {
            CacheItem::validateKey(\is_int($key) ? (string) $key : $key);
            $objectsCount = 0;

            if (null === $value) {
                $value = 'N;';
            } elseif (\is_object($value) || \is_array($value)) {
                try {
                    $e = null;
                    $serialized = serialize($value);
                } catch (\Exception $e) {
                }
                if (null !== $e || false === $serialized) {
                    throw new InvalidArgumentException(sprintf('Cache key "%s" has non-serializable %s value.', $key, \is_object($value) ? \get_class($value) : 'array'), 0, $e);
                }
                // Keep value serialized if it contains any internal references
                $value = false !== strpos($serialized, ';R:') ? $serialized : PhpMarshaller::marshall($value, $objectsCount);
            } elseif (\is_string($value)) {
                // Wrap strings if they could be confused with serialized objects or arrays
                if ('N;' === $value || (isset($value[2]) && ':' === $value[1])) {
                    ++$objectsCount;
                }
            } elseif (!\is_scalar($value)) {
                throw new InvalidArgumentException(sprintf('Cache key "%s" has non-serializable %s value.', $key, \gettype($value)));
            }

            $value = var_export($value, true);
            if ($objectsCount) {
                $value = PhpMarshaller::optimize($value);
                $value = "static function () {\nreturn {$value};\n}";
            }
            $hash = hash('md5', $value);

            if (null === $id = $dumpedMap[$hash] ?? null) {
                $id = $dumpedMap[$hash] = \count($dumpedMap);
                $dumpedValues .= "{$id} => {$value},\n";
            }

            $dump .= var_export($key, true)." => {$id},\n";
        }

        $dump .= "\n), array(\n\n{$dumpedValues}\n));\n";

        $tmpFile = uniqid($this->file, true);

        file_put_contents($tmpFile, $dump);
        @chmod($tmpFile, 0666 & ~umask());
        unset($serialized, $value, $dump);

        @rename($tmpFile, $this->file);

        $this->initialize();
    }

    /**
     * {@inheritdoc}
     */
    public function clear()
    {
        $this->keys = $this->values = array();

        $cleared = @unlink($this->file) || !file_exists($this->file);

        return $this->pool->clear() && $cleared;
    }

    /**
     * Load the cache file.
     */
    private function initialize()
    {
        if (!file_exists($this->file)) {
            $this->keys = $this->values = array();

            return;
        }
        $values = (include $this->file) ?: array(array(), array());

        if (2 !== \count($values) || !isset($values[0], $values[1])) {
            $this->keys = $this->values = array();
        } else {
            list($this->keys, $this->values) = $values;
        }
    }
}