<?php

declare(strict_types=1);

namespace Nayvo\LaravelRaceGuard\Tests\Git;

use Nayvo\LaravelRaceGuard\Git\GitRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class GitRepositoryTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = sys_get_temp_dir().'/raceguard-git-'.bin2hex(random_bytes(6));
        mkdir($this->repo, 0777, true);
        mkdir($this->repo.'/app', 0777, true);

        $this->git(['init', '-q', '-b', 'main']);
        $this->git(['config', 'user.email', 'test@example.com']);
        $this->git(['config', 'user.name', 'RaceGuard Test']);
        $this->git(['config', 'commit.gpgsign', 'false']);

        file_put_contents($this->repo.'/app/Wallet.php', "<?php\n\nreturn 1;\nreturn 2;\nreturn 3;\n");
        $this->git(['add', '.']);
        $this->git(['commit', '-q', '-m', 'initial']);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->repo);
        parent::tearDown();
    }

    #[Test]
    public function it_detects_the_working_tree(): void
    {
        $this->assertTrue($this->repository()->isAvailable());
    }

    #[Test]
    public function it_reports_a_non_git_directory_as_unavailable(): void
    {
        $plain = sys_get_temp_dir().'/raceguard-plain-'.bin2hex(random_bytes(6));
        mkdir($plain);

        try {
            $this->assertFalse((new GitRepository($plain))->isAvailable());
        } finally {
            $this->removeDirectory($plain);
        }
    }

    #[Test]
    public function it_lists_modified_files_and_the_lines_that_changed(): void
    {
        // Modify line 4 of the tracked file.
        file_put_contents($this->repo.'/app/Wallet.php', "<?php\n\nreturn 1;\nreturn 20;\nreturn 3;\n");

        $repo = $this->repository();

        $this->assertContains('app/Wallet.php', $repo->dirtyFiles());
        $this->assertContains(4, $repo->changedLines('app/Wallet.php'));
        $this->assertNotContains(3, $repo->changedLines('app/Wallet.php'));
    }

    #[Test]
    public function it_treats_untracked_files_as_entirely_new(): void
    {
        file_put_contents($this->repo.'/app/New.php', "<?php\n\nreturn 99;\n");

        $repo = $this->repository();

        $this->assertContains('app/New.php', $repo->dirtyFiles());
        // A brand new file has no diff base, so the whole file is "changed".
        $this->assertNull($repo->changedLines('app/New.php'));
    }

    #[Test]
    public function it_lists_only_staged_files_when_asked(): void
    {
        file_put_contents($this->repo.'/app/Staged.php', "<?php\n\nreturn 1;\n");
        file_put_contents($this->repo.'/app/Unstaged.php', "<?php\n\nreturn 2;\n");
        $this->git(['add', 'app/Staged.php']);

        $staged = $this->repository()->stagedFiles();

        $this->assertContains('app/Staged.php', $staged);
        $this->assertNotContains('app/Unstaged.php', $staged);
    }

    private function repository(): GitRepository
    {
        return new GitRepository($this->repo);
    }

    /**
     * @param  array<int, string>  $args
     */
    private function git(array $args): void
    {
        $process = new Process(array_merge(['git'], $args), $this->repo);
        $process->run();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
