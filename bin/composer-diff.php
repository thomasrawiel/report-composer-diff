<?php
$autoloadPaths = [
    // Package was included as a library
    __DIR__ . '/../../../autoload.php',
    // Local package usage
    __DIR__ . '/../vendor/autoload.php',
    // Local package in packages folder
    __DIR__ . '/../../../vendor/autoload.php',
];

foreach ($autoloadPaths as $path) {
    if (! file_exists($path)) {
        continue;
    }

    include_once $path;
    break;
}

$app = new \Symfony\Component\Console\Application('ComposerDiff');
$command = new \TRAW\ReportComposerDiff\Command\ComposerDiffCommand('composer-diff');
$app->add($command);
$app->setDefaultCommand($command->getName(), true);
$app->run();