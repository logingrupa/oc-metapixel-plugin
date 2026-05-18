<?php

/*
 * Worktree bootstrap shim. Wraps October's standard bootstrap, then registers
 * a higher-priority PSR-4 autoloader that resolves Logingrupa\Metapixel\* to
 * the worktree dir (overriding the master plugin tree). Letting Composer's
 * default loader find master files first would shadow worktree changes.
 *
 * Used only inside a Claude Code worktree. Never committed to the master
 * autoload chain.
 */

require_once '/home/forge/nailscosmetics.lv/modules/system/tests/bootstrap.php';

$sWorktreeRoot = dirname(__DIR__);

spl_autoload_register(static function (string $sClass) use ($sWorktreeRoot): void {
    $sPrefix = 'Logingrupa\\Metapixel\\';
    if (strncmp($sClass, $sPrefix, strlen($sPrefix)) !== 0) {
        return;
    }
    $sRelative = substr($sClass, strlen($sPrefix));
    // Tests\\* — only NEW worktree test files. Existing infra (MetapixelTestCase,
    // ShopaholicAdapterTestCase) carries a __DIR__-relative require to October's
    // bootstrap/app.php that breaks under the deeper worktree path. For those
    // two classes, fall through to the master PSR-4 loader (file shipped at the
    // master plugin tree where __DIR__ resolves correctly). New test files
    // (ShopaholicCartPositionAdapterContractTest, etc.) are discovered by Pest
    // directory scan, not autoload.
    // Only MetapixelTestCase carries the __DIR__-relative require to October's
    // bootstrap/app.php; that path resolves wrong from the deeper worktree.
    // Every other test class (including ShopaholicAdapterTestCase, which Task 1
    // amended) loads safely from the worktree.
    $arInfraBlocklist = ['Tests\\MetapixelTestCase'];
    if (in_array($sRelative, $arInfraBlocklist, true)) {
        return;
    }
    if (str_starts_with($sRelative, 'Tests\\')) {
        // Tests dir is lowercase 'tests/' but classnames keep PascalCase below it.
        $sPath = $sWorktreeRoot.'/tests/'.str_replace('\\', '/', substr($sRelative, strlen('Tests\\'))).'.php';
    } else {
        // Production code uses lowercase top-level dirs (D-25): Classes/ -> classes/,
        // Models/ -> models/, Console/ -> console/. Lowercase the path's FIRST
        // segment after the namespace prefix.
        $arSegments = explode('\\', $sRelative);
        $arSegments[0] = strtolower($arSegments[0]);
        if (isset($arSegments[1])) {
            // Lowercase the second segment too — shopaholic/, adapter/, event/, etc.
            // Lovata.Toolbox + D-25 mandate lowercase dirs at every level except
            // class file leaves.
            for ($iIdx = 1; $iIdx < count($arSegments) - 1; $iIdx++) {
                $arSegments[$iIdx] = strtolower($arSegments[$iIdx]);
            }
        }
        $sPath = $sWorktreeRoot.'/'.implode('/', $arSegments).'.php';
    }
    if (is_file($sPath)) {
        require $sPath;
    }
}, true, true);
