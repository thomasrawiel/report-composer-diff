<?php
declare(strict_types=1);

namespace TRAW\ReportComposerDiff\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Input\InputOption;

class ComposerDiffCommand extends Command
{
    protected static $defaultName = 'composer:diff';

    protected function configure(): void
    {
        $this
            ->setName('compare:composer-diff')
            ->setDescription('Compares composer.lock between two Git refs')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Source Git ref')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Target Git ref')
            ->addOption('repo', null, InputOption::VALUE_OPTIONAL, 'Path to Git repository', getcwd())
            ->addOption(
                'group',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'Custom package group(s) in the format "groupName:namespacePrefix" (e.g. "mygroup:mycompany")'
            )
            ->addOption('html', null, InputOption::VALUE_NONE, 'Output as HTML')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON')
            ->addOption('md', null, InputOption::VALUE_NONE, 'Output as MD')
            ->addOption('txt', null, InputOption::VALUE_NONE, 'Output as txt')
            ->addOption('filename', null, InputOption::VALUE_OPTIONAL, 'Target output filename (must match format)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fromRef = $input->getOption('from');
        $toRef = $input->getOption('to');
        $repo = $input->getOption('repo');
        $isHtml = $input->getOption('html');
        $isJson = $input->getOption('json');
        $isMd = $input->getOption('md');
        $isTxt = $input->getOption('txt');
        $filename = $input->getOption('filename');

        if ($isHtml && $filename && !str_ends_with($filename, '.html')) {
            throw new \RuntimeException('--filename must end in .html when using --html');
        }
        if ($isJson && $filename && !str_ends_with($filename, '.json')) {
            throw new \RuntimeException('--filename must end in .json when using --json');
        }
        if ($isMd && $filename && !str_ends_with($filename, '.md')) {
            throw new \RuntimeException('--filename must end in .md when using --md');
        }
        if ($isTxt && $filename && !str_ends_with($filename, '.txt')) {
            throw new \RuntimeException('--filename must end in .txt when using --txt');
        }

        if (!$filename) {
            if ($isHtml) {
                $filename = 'report.html';
            } elseif ($isJson) {
                $filename = 'report.json';
            } elseif ($isMd) {
                $filename = 'report.md';
            } elseif ($isTxt) {
                $filename = 'report.txt';
            }
        }

        chdir($repo);

        // Determine fallback tags
        $tags = explode("\n", trim(shell_exec("git tag --sort=-creatordate | head -n 2")));
        [$latestTag, $previousTag] = $tags + [null, null];

        if (!$fromRef && !$toRef) {
            if (!$latestTag || !$previousTag) {
                $output->writeln('<error>Not enough Git tags found to compare.</error>');
                return Command::FAILURE;
            }
            $fromRef = $previousTag;
            $toRef = $latestTag;
        } elseif (!$fromRef) {
            if (!$latestTag) {
                $output->writeln('<error>No Git tags found to determine fallback "from" reference.</error>');
                return Command::FAILURE;
            }

            $fromRef = ($toRef !== $latestTag) ? $latestTag : $previousTag;

            if (!$fromRef) {
                $output->writeln('<error>Could not determine fallback "from" reference. Provide both --from and --to.</error>');
                return Command::FAILURE;
            }

            $output->writeln("<info>Using fallback fromRef={$fromRef}</info>");
        } elseif (!$toRef) {
            if (!$latestTag) {
                $output->writeln('<error>No Git tags found to determine fallback "to" reference.</error>');
                return Command::FAILURE;
            }

            $toRef = ($fromRef !== $latestTag) ? $latestTag : $previousTag;

            if (!$toRef) {
                $output->writeln('<error>Could not determine fallback "to" reference. Provide both --from and --to.</error>');
                return Command::FAILURE;
            }

            $output->writeln("<info>Using fallback toRef={$toRef}</info>");
        }

        $output->writeln("<comment>Comparing from {$fromRef} to {$toRef}</comment>");


        // Verify refs actually exist
        foreach ([$fromRef, $toRef] as $ref) {
            $check = shell_exec("git rev-parse --verify --quiet $ref^{commit}");
            if (!$check) {
                $output->writeln("<error>Git reference not found: $ref</error>");
                return Command::FAILURE;
            }
        }

        if ($isHtml && $filename && !str_ends_with($filename, '.html')) {
            throw new \RuntimeException('--filename must end in .html when using --html');
        }
        if ($isJson && $filename && !str_ends_with($filename, '.json')) {
            throw new \RuntimeException('--filename must end in .json when using --json');
        }

        $getComposerLock = fn(string $tag): array => json_decode(
            (new Process(['git', '-C', $repo, 'show', "$tag:composer.lock"]))->mustRun()->getOutput(),
            true
        );

        $fromLock = $getComposerLock($fromRef);
        $toLock = $getComposerLock($toRef);

        $classify = fn(array $from, array $to): array => [
            'added' => array_udiff($to, $from, fn($a, $b) => strcmp($a['name'], $b['name'])),
            'removed' => array_udiff($from, $to, fn($a, $b) => strcmp($a['name'], $b['name'])),
            'updated' => array_uintersect($from, $to, fn($a, $b) => strcmp($a['name'], $b['name']))
                ? array_filter($to, fn($pkg) => isset($fromMap[$pkg['name']]) && $fromMap[$pkg['name']]['version'] !== $pkg['version'])
                : [],
            'unchanged' => array_filter($to, fn($pkg) => isset($fromMap[$pkg['name']]) && $fromMap[$pkg['name']]['version'] === $pkg['version']),
        ];

        $fromMap = array_column($fromLock['packages'] ?? [], null, 'name');
        $toMap = array_column($toLock['packages'] ?? [], null, 'name');

        $customGroups = [];
        foreach ($input->getOption('group') as $g) {
            [$name, $prefix] = explode(':', $g, 2) + [null, null];
            if ($name && $prefix) {
                $customGroups[$name] = $prefix;
            }
        }


        $grouped = [
            'typo3-core' => [],
            'typo3-core-extensions' => [],
            'typo3-extensions' => [],
        ];
        foreach ($customGroups as $groupName => $_) {
            $grouped[$groupName] = [];
        }
        $grouped['other'] = [];

        foreach ($toMap + $fromMap as $name => $pkg) {
            $type = $pkg['type'] ?? '';

            $group = 'other'; // default

            foreach ($customGroups as $groupName => $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $group = $groupName;
                    break;
                }
            }

            if($group === 'other') {
            if ($name === 'typo3/cms-core') {
                $group = 'typo3-core';
                } elseif ($type === 'typo3-cms-framework') {
                    $group = 'typo3-core-extensions';
                } elseif ($type === 'typo3-cms-extension') {
                    $group = 'typo3-extensions';
                }
            }
            $grouped[$group][$name] = [
                'from' => $fromMap[$name]['version'] ?? null,
                'to' => $toMap[$name]['version'] ?? null,
            ];
        }

        $report = [];
        foreach ($grouped as $group => $packages) {
            foreach ($packages as $name => $vers) {
                $status = 'unchanged';
                if ($vers['from'] === null) {
                    $status = 'added';
                } elseif ($vers['to'] === null) {
                    $status = 'removed';
                } elseif ($vers['from'] !== $vers['to']) {
                    $status = 'updated';
                }
                $report[$group][$status][$name] = $vers;
            }
        }

        foreach ($report as $g => &$s) {
            foreach ($s as &$e) {
                ksort($e);
            }
        }

        if ($isJson) {
            $jsonOutput = json_encode($report, JSON_PRETTY_PRINT);
            $fileWritten = $this->writeFile($filename, $jsonOutput);
            $output->writeln("<info>File written to {$fileWritten}</info>");
        } elseif ($isHtml) {
            $css = 'body,table{background-color:hsl(0 0% 100%)}h2,th{font-weight:600;color:hsl(222.2 84% 4.9%)}body,h2,td:nth-child(2),th{color:hsl(222.2 84% 4.9%)}*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Oxygen,Ubuntu,Cantarell,sans-serif;line-height:1.6;min-height:100vh;max-width:800px;margin:0 auto;padding:2rem 1rem}h2{font-size:1.875rem;margin:2rem 0 1rem;padding-bottom:.5rem;border-bottom:1px solid}td,th{padding:.75rem 1rem;border-bottom:1px solid;font-size:.875rem}h2:first-of-type{margin-top:0}table{width:100%;border-collapse:collapse;margin-bottom:2rem;border-radius:.5rem;border:1px solid;overflow:hidden}th{background-color:hsl(210 40% 98%);text-align:left}tr:last-child td{border-bottom:none}.added{background-color:hsl(143 85% 96%);border-left:3px solid hsl(142 76% 36%)}.removed{background-color:hsl(0 86% 97%);border-left:3px solid hsl(0 84% 60%)}.updated{background-color:hsl(48 100% 96%);border-left:3px solid hsl(45 93% 47%)}.unchanged{background-color:hsl(210 40% 98%);border-left:3px solid hsl(215 16% 47%)}td:first-child{font-weight:500;font-size:.75rem;text-transform:uppercase;letter-spacing:.025em}td:nth-child(2),td:nth-child(3),td:nth-child(4){font-family:ui-monospace,SFMono-Regular,"SF Mono",Consolas,"Liberation Mono",Menlo,monospace;font-size:.8125rem}.added td:first-child{color:hsl(142 76% 36%)}.removed td:first-child{color:hsl(0 84% 60%)}.updated td:first-child{color:hsl(45 93% 47%)}.unchanged td:first-child{color:hsl(215 16% 47%)}td:nth-child(2){font-weight:500}td:nth-child(3),td:nth-child(4){color:hsl(215.4 16.3% 46.9%);font-weight:400}td:nth-child(4){color:hsl(142 76% 36%);font-weight:500}@media (max-width:768px){td:nth-child(2),td:nth-child(3),td:nth-child(4){font-size:.75rem}body{padding:1rem .5rem}h2{font-size:1.5rem;margin:1.5rem 0 .75rem}td,th{padding:.5rem .75rem;font-size:.8125rem}td:nth-child(2){word-break:break-all}}@media (max-width:480px){body{padding:1rem .25rem}table{font-size:.75rem}td,th{padding:.5rem}h2{font-size:1.25rem}}@media print{body{max-width:none;margin:0;padding:1rem}table{border:1px solid}}@media (prefers-color-scheme:dark){body,table{background-color:hsl(222.2 84% 4.9%)}h2,td,th{border-bottom-color:hsl(217.2 32.6% 17.5%)}body,h2,td:nth-child(2),th{color:hsl(210 40% 98%)}table{border-color:hsl(217.2 32.6% 17.5%)}th{background-color:hsl(217.2 32.6% 17.5%)}td:nth-child(3){color:hsl(215.4 16.3% 56.9%)}.added{background-color:hsl(142 76% 6%)}.removed{background-color:hsl(0 84% 6%)}.updated{background-color:hsl(45 93% 6%)}.unchanged{background-color:hsl(217.2 32.6% 17.5%)}}';
            $htmlOutput = '<html><head><style>' . $css . '</style></head><body>';
            foreach ($report as $group => $statuses) {
                $htmlOutput .= "<h2>$group</h2><table><tr><th>Status</th><th>Package</th><th>From</th><th>To</th></tr>";
                foreach ($statuses as $status => $entries) {
                    foreach ($entries as $name => $vers) {
                        $htmlOutput .= "<tr class='$status'><td>$status</td><td>$name</td><td>{$vers['from']}</td><td>{$vers['to']}</td></tr>";
                    }
                }
                $htmlOutput .= '</table>';
            }
            $htmlOutput .= '</body></html>';
            $fileWritten = $this->writeFile($filename, $htmlOutput);
            $output->writeln("<info>File written to {$fileWritten}</info>");
        } elseif ($isMd) {
            $mdOutput = '';
            foreach ($report as $group => $statuses) {
                $mdOutput .= "## $group\n\n";
                foreach ($statuses as $status => $entries) {
                    if (!$entries) continue;
                    $mdOutput .= "### $status\n\n";
                    $mdOutput .= "| Package | From | To |\n|---|---|---|\n";
                    foreach ($entries as $name => $vers) {
                        $mdOutput .= "| $name | {$vers['from']} | {$vers['to']} |\n";
                    }
                    $mdOutput .= "\n";
                }
            }
            $fileWritten = $this->writeFile($filename, $mdOutput);
            $output->writeln("<info>File written to {$fileWritten}</info>");

        } elseif ($isTxt) {
            $txtOutput = '';
            foreach ($report as $group => $statuses) {
                $txtOutput .= strtoupper($group) . "\n";
                foreach ($statuses as $status => $entries) {
                    if (!$entries) continue;
                    $txtOutput .= "  $status\n";
                    foreach ($entries as $name => $vers) {
                        $txtOutput .= "    $name: {$vers['from']} -> {$vers['to']}\n";
                    }
                }
                $txtOutput .= "\n";
            }
            $fileWritten = $this->writeFile($filename, $txtOutput);
            $output->writeln("<info>File written to {$fileWritten}</info>");

        } else {
            //console output
            foreach ($report as $group => $statuses) {
                $output->writeln("\n<info>$group</info>");
                foreach ($statuses as $status => $entries) {
                    if (!$entries) continue;
                    $output->writeln(" <comment>$status</comment>");
                    foreach ($entries as $name => $vers) {
                        $output->writeln("   $name: {$vers['from']} -> {$vers['to']}");
                    }
                }
            }
        }

        return Command::SUCCESS;
    }

    private function writeFile(string $filename, string $content): string
    {
        $dir = dirname($filename);

        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
        }

        if (file_put_contents($filename, $content) === false) {
            throw new \RuntimeException(sprintf('Failed to write file "%s"', $filename));
        }

        return realpath(($dir === '.' ? './' : '') . $filename);
    }
}
