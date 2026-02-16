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

        $getComposerLock = fn(string $tag): array => json_decode(
            (new Process(['git', '-C', $repo, 'show', "$tag:composer.lock"]))->mustRun()->getOutput(),
            true
        );

        $fromLock = $getComposerLock($fromRef);
        $toLock = $getComposerLock($toRef);

        $fromMap = array_column($fromLock['packages'] ?? [], null, 'name');
        $toMap = array_column($toLock['packages'] ?? [], null, 'name');

        $customGroups = [];
        foreach ($input->getOption('group') as $g) {
            [$name, $prefixesString] = array_pad(explode(':', $g, 2), 2, '');
            $prefixes = array_map('trim', explode(',', $prefixesString));
            if ($name && $prefixesString !== '') {
                $customGroups[$name] = $prefixes;
            }
        }

        $allPrefixes = [];
        foreach ($customGroups as $groupName => $prefixes) {
            foreach ($prefixes as $prefix) {
                if (isset($allPrefixes[$prefix])) {
                    throw new \RuntimeException(sprintf(
                        'Prefix "%s" is defined in multiple groups: "%s" and "%s"',
                        $prefix,
                        $allPrefixes[$prefix],
                        $groupName
                    ));
                }
                $allPrefixes[$prefix] = $groupName;
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

            foreach ($customGroups as $groupName => $prefixes) {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        $group = $groupName;
                        break 2;
                    }
                }
            }

            if ($group === 'other') {
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
                'fromRef' => $fromMap[$name]['source']['reference'] ?? null,
                'toRef' => $toMap[$name]['source']['reference'] ?? null,
            ];
        }

        $report = [];
        foreach ($grouped as $group => $packages) {
            foreach ($packages as $name => $vers) {
                $status = 'unchanged';

                $fromVersion = $vers['from'] ?? null;
                $toVersion = $vers['to'] ?? null;

                $fromRef = $vers['fromRef'] ?? null;
                $toRef = $vers['toRef'] ?? null;

                if ($fromVersion === null) {
                    $status = 'added';
                } elseif ($toVersion === null) {
                    $status = 'removed';
                } elseif ($fromVersion !== $toVersion) {
                    //version change 1.0.0 => 1.2.0
                    $status = 'updated';
                } elseif (
                    //branch change dev-develop (hashold) => dev-develop (hashnew)
                    str_starts_with((string)$fromVersion, 'dev-') &&
                    $fromRef !== null && $toRef !== null &&
                    $fromRef !== $toRef
                ) {
                    $status = 'updated';
                    $fromVersion .= ' (' . substr($fromRef, 0, 7) . ')';
                    $toVersion .= ' (' . substr($toRef, 0, 7) . ')';

                    $vers['from'] = $fromVersion;
                    $vers['to'] = $toVersion;
                }

                $report[$group][$status][$name] = $vers;
            }
        }

        foreach ($report as $g => &$s) {
            foreach ($s as &$e) {
                ksort($e);
            }
        }

        $summary = [];
        foreach ($report as $group => $statuses) {
            $summary[$group] = [
                'added' => count($statuses['added'] ?? []),
                'removed' => count($statuses['removed'] ?? []),
                'updated' => count($statuses['updated'] ?? []),
                'unchanged' => count($statuses['unchanged'] ?? []),
            ];
        }

        if ($isJson) {
            $jsonOutput = json_encode([
                'summary' => $summary,
                'report' => $report,
            ], JSON_PRETTY_PRINT);
            $fileWritten = $this->writeFile($filename ?? 'report.json', $jsonOutput);
            $output->writeln("<info>File written to {$fileWritten}</info>");
        } elseif ($isHtml) {
            $css = $this->getCss();
            $htmlOutput = '<html><head><style>' . $css . '</style></head><body>';

            $htmlOutput .= '<h2>Contents</h2><ul class="contents"><li><a href="#summary">Summary</a></li>';
            foreach ($report as $group => $statuses) {
                $htmlOutput .= '<li><a href="#' . $group . '">' . $group . '</a></li>';
            }
            $htmlOutput .= '</ul>';

            $htmlOutput .= '<details open id="summary"><summary><h2>Summary per group</h2></summary><table class="summary-table"><tr><th>Group</th><th>Added</th><th>Removed</th><th>Updated</th><th>Unchanged</th></tr>';
            foreach ($summary as $group => $counts) {
                $htmlOutput .= "<tr>
                    <td>$group</td>
                    <td class='added'>{$counts['added']}</td>
                    <td class='removed'>{$counts['removed']}</td>
                    <td class='updated'>{$counts['updated']}</td>
                    <td class='unchanged'>{$counts['unchanged']}</td>
                </tr>";
            }
            $htmlOutput .= '</table></details>';

            foreach ($report as $group => $statuses) {
                $htmlOutput .= '<details' . (in_array($group, ['typo3-core-extensions', 'other'], true) ? '' : ' open') . ' id="' . $group . '"><summary><h2>' . $group . '</h2></summary><table><tr><th>Status</th><th>Package</th><th>From</th><th>To</th></tr>';
                foreach ($statuses as $status => $entries) {
                    foreach ($entries as $name => $vers) {
                        $htmlOutput .= "<tr class='$status'><td>$status</td><td>$name</td><td>{$vers['from']}</td><td>{$vers['to']}</td></tr>";
                    }
                }
                $htmlOutput .= '</table></details>';
            }
            $htmlOutput .= '</body></html>';
            $fileWritten = $this->writeFile($filename, $htmlOutput);
            $output->writeln("<info>File written to {$fileWritten}</info>");
        } elseif ($isMd) {
            $mdOutput = "## Summary per group\n\n";
            $mdOutput .= "| Group | Added | Removed | Updated | Unchanged |\n|---|---|---|---|---|\n";
            foreach ($summary as $group => $counts) {
                $mdOutput .= "| $group | {$counts['added']} | {$counts['removed']} | {$counts['updated']} | {$counts['unchanged']} |\n";
            }
            $mdOutput .= "\n";
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
            $txtOutput = "SUMMARY PER GROUP\n";
            foreach ($summary as $group => $counts) {
                $txtOutput .= strtoupper($group) . ": added={$counts['added']}, removed={$counts['removed']}, updated={$counts['updated']}, unchanged={$counts['unchanged']}\n";
            }
            $txtOutput .= "\n";
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
            $output->writeln("\n<info>Summary per group</info>");
            foreach ($summary as $group => $counts) {
                $output->writeln("  <comment>$group</comment>: added={$counts['added']}, removed={$counts['removed']}, updated={$counts['updated']}, unchanged={$counts['unchanged']}");
            }

            // Print detailed report with color per status
            foreach ($report as $group => $statuses) {
                $output->writeln("\n<info>$group</info>");
                foreach ($statuses as $status => $entries) {
                    if (!$entries) continue;

                    // Assign colors
                    $color = match ($status) {
                        'added' => 'green',
                        'removed' => 'red',
                        'updated' => 'yellow',
                        'unchanged' => 'gray',
                        default => 'white'
                    };

                    $output->writeln(" <fg=$color>$status</>");
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

    private function getVersionChangeType(?string $from, ?string $to): string
    {
        if (!$from || !$to) {
            return 'added'; // or 'removed', depending on context
        }

        [$fromMajor, $fromMinor, $fromPatch] = array_map('intval', explode('.', preg_replace('/[^0-9.]/', '', $from) . '.0.0'));
        [$toMajor, $toMinor, $toPatch] = array_map('intval', explode('.', preg_replace('/[^0-9.]/', '', $to) . '.0.0'));

        return match (true) {
            $fromMajor !== $toMajor => 'major',
            $fromMinor !== $toMinor => 'minor',
            $fromPatch !== $toPatch => 'patch',
            default => 'unchanged'
        };
    }

    private function getCss(): string
    {
        return "
        body,
        table {
            background-color: hsl(0 0% 100%);
        }

        h2,
        th {
            font-weight: 600;
            color: hsl(222.2 84% 4.9%);
        }

        body,
        h2,
        td:nth-child(2),
        th {
            color: hsl(222.2 84% 4.9%);
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.6;
            min-height: 100vh;
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        h2 {
            font-size: 1.875rem;
            margin: 2rem 0 1rem;
            padding-bottom: .5rem;
            border-bottom: 1px solid;
        }

        summary {
            cursor: pointer;
        }

        summary h2 {
            display: inline-block;
            width: calc(100% - 2rem);
            padding-left: 1rem;
        }

        .contents {
            display:block;
            margin-left: 1.5rem;
            margin-bottom: 3rem;
        }

        a {
           color: hsl(210 40% 98%);
           margin-bottom: 3rem;
        }

        td,
        th {
            padding: .75rem 1rem;
            border-bottom: 1px solid;
            font-size: .875rem;
        }

        h2:first-of-type {
            margin-top: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2rem;
            border-radius: .5rem;
            border: 1px solid;
            overflow: hidden;
        }

        th {
            background-color: hsl(210 40% 98%);
            text-align: left;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .added {
            background-color: hsl(143 85% 96%);
            border-left: 3px solid hsl(142 76% 36%);
        }

        .removed {
            background-color: hsl(0 86% 97%);
            border-left: 3px solid hsl(0 84% 60%);
        }

        .updated {
            background-color: hsl(48 100% 96%);
            border-left: 3px solid hsl(45 93% 47%);
        }

        .unchanged {
            background-color: hsl(210 40% 98%);
            border-left: 3px solid hsl(215 16% 47%);
        }

        td:first-child {
            font-weight: 500;
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .025em;
        }

        td:nth-child(2),
        td:nth-child(3),
        td:nth-child(4) {
            font-family: ui-monospace, SFMono-Regular, \"SF Mono\", Consolas, \"Liberation Mono\", Menlo, monospace;
            font-size: .8125rem;
        }

        .added td:first-child {
            color: hsl(142 76% 36%);
        }

        .removed td:first-child {
            color: hsl(0 84% 60%);
        }

        .updated td:first-child {
            color: hsl(45 93% 47%);
        }

        .unchanged td:first-child {
            color: hsl(215 16% 47%);
        }

        td:nth-child(2) {
            font-weight: 500;
        }

        td:nth-child(3),
        td:nth-child(4) {
            color: hsl(215.4 16.3% 46.9%);
            font-weight: 400;
        }

        td:nth-child(4) {
            color: hsl(142 76% 36%);
            font-weight: 500;
        }

        @media (max-width: 768px) {
            td:nth-child(2),
            td:nth-child(3),
            td:nth-child(4) {
                font-size: .75rem;
            }

            body {
                padding: 1rem .5rem;
            }

            h2 {
                font-size: 1.5rem;
                margin: 1.5rem 0 .75rem;
            }

            td,
            th {
                padding: .5rem .75rem;
                font-size: .8125rem;
            }

            td:nth-child(2) {
                word-break: break-all;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 1rem .25rem;
            }

            table {
                font-size: .75rem;
            }

            td,
            th {
                padding: .5rem;
            }

            h2 {
                font-size: 1.25rem;
            }
        }

        @media print {
            body {
                max-width: none;
                margin: 0;
                padding: 1rem;
            }

            table {
                border: 1px solid;
            }
        }

        @media (prefers-color-scheme: dark) {
            body,
            table {
                background-color: hsl(222.2 84% 4.9%);
            }

            h2,
            td,
            th {
                border-bottom-color: hsl(217.2 32.6% 17.5%);
            }

            body,
            h2,
            td:nth-child(2),
            th {
                color: hsl(210 40% 98%);
            }

            table {
                border-color: hsl(217.2 32.6% 17.5%);
            }

            th {
                background-color: hsl(217.2 32.6% 17.5%);
            }

            td:nth-child(3) {
                color: hsl(215.4 16.3% 56.9%);
            }

            .added {
                background-color: hsl(142 76% 6%);
            }

            .removed {
                background-color: hsl(0 84% 6%);
            }

            .updated {
                background-color: hsl(45 93% 6%);
            }

            .unchanged {
                background-color: hsl(217.2 32.6% 17.5%);
            }
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* Summary table tweaks */
        .summary-table td:first-child {
            text-transform: none;
            /* no uppercase for group names */
            font-weight: 600;
            font-size: .875rem;
            letter-spacing: normal;
        }

        /* Color numbers according to status */
        .summary-table .added {
            color: hsl(142 76% 36%);
        }

        .summary-table .removed {
            color: hsl(0 84% 60%);
        }

        .summary-table .updated {
            color: hsl(45 93% 47%);
        }

        .summary-table .unchanged {
            color: hsl(215 16% 47%);
        }
        ";
    }
}
