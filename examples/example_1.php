<?php

declare(strict_types = 1);

require_once __DIR__ . "/TrivialLoop.php";

function getCallback(int $id) {
    return function () use ($id) {
        for ($i = 0; $i < 20; $i += 1) {
            echo "callback $id count: $i\n";
            sleep(1);
        }
    };
};

$loop = new TrivialLoop();

$loop->add(getCallback(1));

echo "About to go\n";
$loop->go();
echo "Fin\n";