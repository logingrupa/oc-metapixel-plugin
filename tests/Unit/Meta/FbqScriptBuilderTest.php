<?php

namespace Logingrupa\Metapixel\Tests\Unit\Meta;

use Logingrupa\Metapixel\Classes\Meta\FbqScriptBuilder;
use Logingrupa\Metapixel\Tests\MetapixelTestCase;

/**
 * Unit coverage for FbqScriptBuilder — the single source of truth for fbq()
 * track-block assembly. Asserts each 4th-arg branch, the JS-encode flags, and
 * a byte-identical guard against the legacy sprintf+buildFbqOptionsObject form.
 */
final class FbqScriptBuilderTest extends MetapixelTestCase
{
    public function test_event_id_and_test_event_code_branch_orders_event_i_d_first(): void
    {
        $sScript = FbqScriptBuilder::build('ViewContent', ['content_ids' => ['SKU-1']], 'eid-1', 'TEST123');

        $this->assertStringContainsString('fbq("track", "ViewContent"', $sScript);
        $this->assertStringContainsString('{eventID: "eid-1", test_event_code: "TEST123"}', $sScript);
        $this->assertLessThan(
            strpos($sScript, 'test_event_code'),
            strpos($sScript, 'eventID'),
            'eventID MUST precede test_event_code in the 4th-arg object',
        );
    }

    public function test_event_id_only_branch_emits_event_i_d_object(): void
    {
        $sScript = FbqScriptBuilder::build('ViewContent', [], 'eid-2', null);

        $this->assertStringContainsString('{eventID: "eid-2"}', $sScript);
        $this->assertStringNotContainsString('test_event_code', $sScript);
    }

    public function test_test_event_code_only_branch_emits_test_event_code_object_without_event_id(): void
    {
        $sScript = FbqScriptBuilder::build('PageView', [], null, 'TEST123');

        $this->assertStringContainsString('{test_event_code: "TEST123"}', $sScript);
        $this->assertStringNotContainsString('eventID', $sScript);
    }

    public function test_neither_event_id_nor_test_code_emits_three_arg_call(): void
    {
        $sScript = FbqScriptBuilder::build('PageView', ['k' => 'v'], null, null);

        $this->assertSame('<script>fbq("track", "PageView", {"k":"v"});</script>', $sScript);
        $this->assertStringNotContainsString('eventID', $sScript);
        $this->assertStringNotContainsString('test_event_code', $sScript);
    }

    public function test_empty_string_event_id_and_test_code_treated_as_absent(): void
    {
        $sScript = FbqScriptBuilder::build('PageView', [], '', '');

        $this->assertSame('<script>fbq("track", "PageView", []);</script>', $sScript);
    }

    public function test_js_encode_flags_hex_escape_unsafe_characters_in_name(): void
    {
        $sUnsafe = '<x>'.chr(34).'&'.chr(39);
        $sScript = FbqScriptBuilder::build($sUnsafe, [], null, null);

        // Each unsafe char is emitted as a \uXXXX escape (canonical flags), so
        // none of the raw bytes appear verbatim and the escaped form is present.
        $sExpectedEscaped = (string) json_encode($sUnsafe, FbqScriptBuilder::JS);
        $this->assertStringContainsString($sExpectedEscaped, $sScript);
        $this->assertStringNotContainsString('<x>', $sScript);
        $this->assertStringNotContainsString($sUnsafe, $sScript);
    }

    public function test_js_const_matches_canonical_encode_flags(): void
    {
        $this->assertSame(
            JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS,
            FbqScriptBuilder::JS,
        );
    }

    public function test_byte_identical_to_legacy_sprintf_for_event_id_branch_without_test_code(): void
    {
        $sName = 'ViewContent';
        $arCustomData = ['content_ids' => ['SKU-42'], 'value' => 9.99, 'currency' => 'EUR'];
        $sEventId = 'eid-001';

        $iFlags = JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS;
        $sLegacy = sprintf(
            '<script>fbq("track", %s, %s, %s);</script>',
            (string) json_encode($sName, $iFlags),
            (string) json_encode($arCustomData, $iFlags),
            '{eventID: '.(string) json_encode($sEventId, $iFlags).'}',
        );

        $this->assertSame($sLegacy, FbqScriptBuilder::build($sName, $arCustomData, $sEventId, null));
    }

    public function test_byte_identical_to_legacy_sprintf_for_three_arg_branch(): void
    {
        $sName = 'PageView';
        $arCustomData = ['site_id' => 1];

        $iFlags = JSON_HEX_TAG | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS;
        $sLegacy = sprintf(
            '<script>fbq("track", %s, %s);</script>',
            (string) json_encode($sName, $iFlags),
            (string) json_encode($arCustomData, $iFlags),
        );

        $this->assertSame($sLegacy, FbqScriptBuilder::build($sName, $arCustomData, null, null));
    }
}
