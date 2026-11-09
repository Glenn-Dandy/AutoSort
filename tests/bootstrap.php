<?php
// Stand-ins for the Nextcloud classes the tested code touches, so the tests run with plain PHP

namespace OCP {
    interface IBinaryFinder {}
    interface ITempManager {}
    interface IRequest {}
}

namespace OCP\DB {
    class Types {
        public const INTEGER = 'integer';
        public const BOOLEAN = 'boolean';
    }
}

namespace OCP\Files {
    interface File {}
    interface IRootFolder {}
    class NotFoundException extends \Exception {}
}

namespace OCP\AppFramework {
    class Http {
        public const STATUS_BAD_REQUEST = 400;
        public const STATUS_NOT_FOUND = 404;
        public const STATUS_INTERNAL_SERVER_ERROR = 500;
    }

    class Controller {
        public function __construct(string $appName, \OCP\IRequest $request) {
        }
    }
}

namespace OCP\AppFramework\Http {
    class DataResponse {
        public function __construct(public $data = [], public int $status = 200) {
        }
    }
}

namespace OCP\AppFramework\Utility {
    interface ITimeFactory {}
}

namespace OCP\AppFramework\Db {
    class DoesNotExistException extends \Exception {}

    abstract class Entity {
        protected $id;

        public function addType(string $field, string $type): void {
        }

        public function getId() {
            return $this->id;
        }

        public function setId($id): void {
            $this->id = $id;
        }

        public function __call(string $method, array $arguments) {
            $field = lcfirst(substr($method, 3));
            if (str_starts_with($method, 'set')) {
                $this->$field = $arguments[0];
                return null;
            }
            return $this->$field;
        }
    }
}

namespace Psr\Log {
    interface LoggerInterface {}
}

namespace {
    function check(string $name, $got, $want): void {
        global $fail;
        if ($got === $want) {
            echo "ok   $name\n";
        } else {
            $fail++;
            echo "FAIL $name: ";
            var_dump($got);
        }
    }

    function finish(): void {
        global $fail;
        echo $fail ? "$fail FAILED\n" : "all ok\n";
        exit($fail ? 1 : 0);
    }
}
