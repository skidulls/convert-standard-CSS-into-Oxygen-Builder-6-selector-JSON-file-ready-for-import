<?php
/**
 * Plugin Name: CSS to Oxygen Importer
 * Description: Admin-only tool to convert CSS into Oxygen/Breakdance Oxy Selectors JSON.
 * Version: 0.1.0
 * Author: Ai and me
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class CTOI_Plugin
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_post_ctoi_convert_css', [$this, 'handle_convert']);
    }

    public function register_admin_page(): void
    {
        add_menu_page(
            'CSS to Oxygen',
            'CSS to Oxygen',
            'manage_options',
            'css-to-oxygen-importer',
            [$this, 'render_admin_page'],
            'dashicons-editor-code',
            81
        );
    }

    public function handle_convert(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }

        check_admin_referer('ctoi_convert_css');

        $css = isset($_POST['ctoi_css']) ? wp_unslash((string) $_POST['ctoi_css']) : '';
        $css = trim($css);

        $json = '';
        $error = '';

        if ($css === '') {
            $error = 'Please paste some CSS first.';
        } else {
            try {
                $converter = new CTOI_Css_To_Oxygen_Converter();
                $result = $converter->convert($css);
                $json = wp_json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        $redirect = add_query_arg(
            [
                'page' => 'css-to-oxygen-importer',
                'ctoi_done' => '1',
            ],
            admin_url('admin.php')
        );

        set_transient(
            'ctoi_result_' . get_current_user_id(),
            [
                'css' => $css,
                'json' => $json,
                'error' => $error,
            ],
            60
        );

        wp_safe_redirect($redirect);
        exit;
    }

    public function render_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }

        $data = get_transient('ctoi_result_' . get_current_user_id());
        if (!is_array($data)) {
            $data = [
                'css' => '',
                'json' => '',
                'error' => '',
            ];
        }

        $css = (string) ($data['css'] ?? '');
        $json = (string) ($data['json'] ?? '');
        $error = (string) ($data['error'] ?? '');

        ?>
        <div class="wrap">
            <h1>CSS to Oxygen Importer</h1>
            <p>Paste CSS, convert it to Oxy Selectors JSON, then copy or download the result.</p>

            <?php if ($error !== ''): ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="ctoi_convert_css">
                <?php wp_nonce_field('ctoi_convert_css'); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="ctoi_css">CSS Input</label></th>
                            <td>
                                <textarea
                                    id="ctoi_css"
                                    name="ctoi_css"
                                    rows="20"
                                    style="width:100%; font-family: monospace;"
                                    placeholder=".shape { width: 100%; color: #333; }"
                                ><?php echo esc_textarea($css); ?></textarea>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button('Convert CSS to Oxygen JSON'); ?>
            </form>

            <hr>

            <h2>JSON Preview</h2>

            <p>
                <button type="button" class="button" id="ctoi-copy-btn">Copy JSON</button>
                <button type="button" class="button" id="ctoi-download-btn">Download JSON</button>
            </p>

            <textarea
                id="ctoi_json"
                rows="24"
                readonly
                style="width:100%; font-family: monospace;"
            ><?php echo esc_textarea($json); ?></textarea>
        </div>

        <script>
        (function () {
            const jsonField = document.getElementById('ctoi_json');
            const copyBtn = document.getElementById('ctoi-copy-btn');
            const downloadBtn = document.getElementById('ctoi-download-btn');

            if (copyBtn) {
                copyBtn.addEventListener('click', async function () {
                    const text = jsonField.value || '';
                    if (!text.trim()) {
                        alert('There is no JSON to copy yet.');
                        return;
                    }

                    try {
                        await navigator.clipboard.writeText(text);
                        alert('JSON copied.');
                    } catch (e) {
                        jsonField.select();
                        document.execCommand('copy');
                        alert('JSON copied.');
                    }
                });
            }

            if (downloadBtn) {
                downloadBtn.addEventListener('click', function () {
                    const text = jsonField.value || '';
                    if (!text.trim()) {
                        alert('There is no JSON to download yet.');
                        return;
                    }

                    const blob = new Blob([text], { type: 'application/json;charset=utf-8' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'oxygen-import.json';
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    URL.revokeObjectURL(url);
                });
            }
        })();
        </script>
        <?php
    }
}

final class CTOI_Css_To_Oxygen_Converter
{
private $breakpointMap = [
    'breakpoint_base' => ['min' => null, 'max' => null],
    'breakpoint_tablet_landscape' => ['min' => 768, 'max' => 1119],
    'breakpoint_tablet_portrait'  => ['min' => 481, 'max' => 767],
    'breakpoint_phone_landscape'  => ['min' => 361, 'max' => 480],
    'breakpoint_phone_portrait'   => ['min' => null, 'max' => 360],
];

    public function convert(string $css): array
    {
        $css = $this->stripComments($css);
        $rules = $this->extractRules($css);

        $selectors = [];

        foreach ($rules as $rule) {
            $breakpointKey = $this->resolveBreakpointKey($rule['media']);

            foreach ($this->splitSelectors($rule['selector']) as $selectorText) {
                $selectorText = trim($selectorText);
                if ($selectorText === '') {
                    continue;
                }

                $parsedSelector = $this->parseSelector($selectorText);
                if ($parsedSelector === null) {
                    continue;
                }

$rootName = $parsedSelector['root_name'];
$childName = $parsedSelector['child_name'];
$mode = $parsedSelector['mode'];

$selectorKey = $mode . '|' . $rootName;

if (!isset($selectors[$selectorKey])) {
    if ($mode === 'custom_root') {
        $selectors[$selectorKey] = $this->makeEmptyCustomSelector($rootName);
    } else {
        $selectors[$selectorKey] = $this->makeEmptySelector($rootName);
    }
}

                $declarations = $this->parseDeclarations($rule['body']);

                if ($childName === null) {
$this->applyDeclarationsToBucket(
    $selectors[$selectorKey],
    $breakpointKey,
    $declarations
);
                } else {
                    $childIndex = $this->ensureChildSelector($selectors[$selectorKey], $childName);
$this->applyDeclarationsToBucket(
    $selectors[$selectorKey]['children'][$childIndex],
    $breakpointKey,
    $declarations
);
                }
            }
        }

        foreach ($selectors as $key => $selector) {
            $selectors[$key] = $this->cleanupSelector($selector);
        }

        return array_values($selectors);
    }
    
    
    
private function makeEmptyCustomSelector(string $literalSelector): array
{
    return [
        'id' => $this->uuidV4(),
        'name' => $literalSelector,
        'type' => 'custom',
        'children' => [],
        'locked' => false,
        'collection' => 'Default',
        'properties' => [],
    ];
}

    private function cleanupSelector(array $selector): array
    {
        if (isset($selector['children']) && is_array($selector['children'])) {
            foreach ($selector['children'] as $i => $child) {
                if (isset($child['properties']) && empty($child['properties'])) {
                    unset($selector['children'][$i]['properties']);
                }
            }

            $selector['children'] = array_values($selector['children']);
        }

        if (isset($selector['properties']) && empty($selector['properties'])) {
            unset($selector['properties']);
        }

        return $selector;
    }

    private function stripComments(string $css): string
    {
        return preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
    }

    private function extractRules(string $css): array
    {
        $rules = [];
        $offset = 0;
        $length = strlen($css);

        while ($offset < $length) {
            $mediaPos = stripos($css, '@media', $offset);

            if ($mediaPos === false) {
                $remaining = substr($css, $offset);
                array_push($rules, ...$this->extractPlainRules($remaining, null));
                break;
            }

            $before = substr($css, $offset, $mediaPos - $offset);
            array_push($rules, ...$this->extractPlainRules($before, null));

            $openBrace = strpos($css, '{', $mediaPos);
            if ($openBrace === false) {
                break;
            }

            $mediaHeader = trim(substr($css, $mediaPos + 6, $openBrace - ($mediaPos + 6)));
            [$blockBody, $closeBrace] = $this->readBalancedBlock($css, $openBrace);
            array_push($rules, ...$this->extractPlainRules($blockBody, $mediaHeader));

            $offset = $closeBrace + 1;
        }

        return $rules;
    }

    private function extractPlainRules(string $css, ?string $media): array
    {
        $rules = [];
        $offset = 0;
        $length = strlen($css);

        while ($offset < $length) {
            $openBrace = strpos($css, '{', $offset);
            if ($openBrace === false) {
                break;
            }

            $selector = trim(substr($css, $offset, $openBrace - $offset));
            if ($selector === '') {
                break;
            }

            [$body, $closeBrace] = $this->readBalancedBlock($css, $openBrace);

            $rules[] = [
                'media' => $media,
                'selector' => $selector,
                'body' => $body,
            ];

            $offset = $closeBrace + 1;
        }

        return $rules;
    }

    private function readBalancedBlock(string $css, int $openBracePos): array
    {
        $depth = 0;
        $length = strlen($css);

        for ($i = $openBracePos; $i < $length; $i++) {
            $char = $css[$i];

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $body = substr($css, $openBracePos + 1, $i - $openBracePos - 1);
                    return [$body, $i];
                }
            }
        }

        return ['', $length - 1];
    }

    private function splitSelectors(string $selectorList): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $length = strlen($selectorList);

        for ($i = 0; $i < $length; $i++) {
            $char = $selectorList[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = trim($buffer);
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }

        return $parts;
    }

private function parseSelector(string $selector): ?array
{
    $selector = trim($selector);

    // Plain root class: .sec-title-4
    if (preg_match('/^\.(?<root>[A-Za-z0-9_-]+)$/', $selector, $m)) {
        return [
            'mode' => 'class_root',
            'root_name' => $m['root'],
            'child_name' => null,
        ];
    }

    // Root class followed by pseudo / descendant / combinator:
    // .sec-title-4:hover
    // .sec-title-4::before
    // .sec-title-4 span
    // .sec-title-4 > img
    if (preg_match('/^\.(?<root>[A-Za-z0-9_-]+)(?<rest>.+)$/', $selector, $m)) {
        $root = $m['root'];
        $rest = trim($m['rest']);

        if ($rest !== '') {
            if (
                str_starts_with($rest, ':') ||
                str_starts_with($rest, '::') ||
                str_starts_with($rest, '[')
            ) {
                return [
                    'mode' => 'class_root',
                    'root_name' => $root,
                    'child_name' => '&' . $rest,
                ];
            }

            if (
                preg_match('/^[ >+~]/', $rest) ||
                preg_match('/^[A-Za-z\.\[#]/', $rest)
            ) {
                return [
                    'mode' => 'class_root',
                    'root_name' => $root,
                    'child_name' => '& ' . ltrim($rest),
                ];
            }
        }
    }

    // Anything more complex becomes a literal custom selector:
    // .dark .sec-title-4
    // body.home .sec-title-4 img
    return [
        'mode' => 'custom_root',
        'root_name' => $selector,
        'child_name' => null,
    ];
}

    private function parseDeclarations(string $body): array
    {
        $declarations = [];
        $buffer = '';
        $depth = 0;
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }

            if ($char === ';' && $depth === 0) {
                $line = trim($buffer);
                if ($line !== '') {
                    $pos = strpos($line, ':');
                    if ($pos !== false) {
                        $prop = trim(substr($line, 0, $pos));
                        $value = trim(substr($line, $pos + 1));
                        if ($prop !== '' && $value !== '') {
                            $declarations[] = ['property' => strtolower($prop), 'value' => $value];
                        }
                    }
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $line = trim($buffer);
        if ($line !== '') {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $prop = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));
                if ($prop !== '' && $value !== '') {
                    $declarations[] = ['property' => strtolower($prop), 'value' => $value];
                }
            }
        }

        return $declarations;
    }

    private function resolveBreakpointKey(?string $media): string
    {
        if ($media === null || trim($media) === '') {
            return 'breakpoint_base';
        }

        $min = null;
        $max = null;

        if (preg_match('/min-width\s*:\s*(\d+)px/i', $media, $m)) {
            $min = (int) $m[1];
        }
        if (preg_match('/max-width\s*:\s*(\d+)px/i', $media, $m)) {
            $max = (int) $m[1];
        }

        foreach ($this->breakpointMap as $key => $range) {
            if ($range['min'] === $min && $range['max'] === $max) {
                return $key;
            }
        }

        if ($max !== null) {
            if ($max <= 360) {
                return 'breakpoint_phone_portrait';
            }
            if ($max <= 480) {
                return 'breakpoint_phone_landscape';
            }
            if ($max <= 767) {
                return 'breakpoint_tablet_portrait';
            }
            if ($max <= 1119) {
                return 'breakpoint_tablet_landscape';
            }
        }

        return 'breakpoint_base';
    }

    private function makeEmptySelector(string $name): array
    {
        return [
            'id' => $this->uuidV4(),
            'name' => $name,
            'type' => 'class',
            'children' => [],
            'locked' => false,
            'collection' => 'Default',
            'properties' => [],
        ];
    }

private function ensureChildSelector(array &$rootSelector, string $childName): int
{
    foreach ($rootSelector['children'] as $index => $child) {
        if (($child['name'] ?? null) === $childName) {
            return $index;
        }
    }

    $rootSelector['children'][] = [
        'id' => $this->uuidV4(),
        'name' => $childName,
        'properties' => [],
        'locked' => false,
    ];

    return (int) array_key_last($rootSelector['children']);
}

    private function applyDeclarationsToBucket(array &$selector, string $breakpointKey, array $declarations): void
    {
        if (!isset($selector['properties'][$breakpointKey]) || !is_array($selector['properties'][$breakpointKey])) {
            $selector['properties'][$breakpointKey] = [];
        }

        $unsupported = [];

        foreach ($declarations as $declaration) {
            if (!$this->mapDeclaration($selector['properties'][$breakpointKey], $declaration['property'], $declaration['value'])) {
                $unsupported[] = "{$declaration['property']}: {$declaration['value']};";
            }
        }

        if ($unsupported !== []) {
            $existing = $selector['properties'][$breakpointKey]['custom_css']['custom_css'] ?? '';
            $extra = ":selector {\n  " . implode("\n  ", $unsupported) . "\n}";
            $selector['properties'][$breakpointKey]['custom_css']['custom_css'] = trim($existing . "\n\n" . $extra);
        }
    }

    private function mapDeclaration(array &$bucket, string $property, string $value): bool
    {
        if ($property === 'margin' || $property === 'padding') {
            $expanded = $this->expandBoxShorthand($value);
            if ($expanded === null) {
                return false;
            }

            foreach ($expanded as $side => $sideValue) {
                $this->mapDeclaration($bucket, "{$property}-{$side}", $sideValue);
            }

            return true;
        }

        if ($property === 'background-color') {
            $bucket['background']['background_color'] = $value;
            return true;
        }

        if ($property === 'display') {
            $bucket['layout']['display'] = $value;
            return true;
        }

        if ($property === 'visibility') {
            $bucket['layout']['visibility'] = $value;
            return true;
        }

        if ($property === 'justify-content') {
            $bucket['layout']['flex_align']['primary_axis'] = $value;
            return true;
        }

        if ($property === 'align-items') {
            $bucket['layout']['flex_align']['cross_axis'] = $value;
            $bucket['layout']['grid_align']['primary_axis'] = $value;
            return true;
        }

        if ($property === 'position') {
            $bucket['position']['position'] = $value;
            return true;
        }

        if (in_array($property, ['top', 'right', 'bottom', 'left'], true)) {
            $parsed = $this->toOxyLength($value);
            if ($parsed === null) {
                return false;
            }
            $bucket['position'][$property] = $parsed;
            return true;
        }

        if ($property === 'z-index') {
            $bucket['position']['z_index'] = is_numeric($value) ? (int) $value : $value;
            return true;
        }

        $sizeMap = [
            'width' => 'width',
            'height' => 'height',
            'min-width' => 'min_width',
            'min-height' => 'min_height',
            'max-width' => 'max_width',
            'max-height' => 'max_height',
        ];

        if (isset($sizeMap[$property])) {
            $parsed = $this->toOxyLength($value);
            if ($parsed === null) {
                return false;
            }
            $bucket['size'][$sizeMap[$property]] = $parsed;
            return true;
        }

        if ($property === 'overflow') {
            $bucket['size']['overflow'] = $value;
            return true;
        }

        if ($property === 'object-fit') {
            $bucket['size']['object_fit'] = $value;
            return true;
        }

        if ($property === 'box-sizing') {
            $bucket['size']['box_sizing'] = $value;
            return true;
        }

        if ($property === 'aspect-ratio') {
            $bucket['size']['aspect_ratio'] = $value;
            return true;
        }

        if ($property === 'object-position') {
            $parsed = $this->parseObjectPosition($value);
            if ($parsed === null) {
                return false;
            }
            $bucket['size']['object_position'] = $parsed;
            return true;
        }

        if ($property === 'color') {
            $bucket['typography']['color'] = $value;
            return true;
        }

        if ($property === 'font-family') {
            $bucket['typography']['font_family'] = trim(trim(explode(',', $value)[0]), "\"'");
            return true;
        }

        if ($property === 'font-weight') {
            $bucket['typography']['font_weight'] = is_numeric($value) ? (int) $value : $value;
            return true;
        }

        if ($property === 'font-size') {
            $parsed = $this->toOxyLength($value, false, true);
            if ($parsed === null) {
                return false;
            }
            $bucket['typography']['font_size'] = $parsed;
            return true;
        }

        if ($property === 'line-height') {
            $parsed = $this->toOxyLength($value, true, true);
            if ($parsed === null) {
                return false;
            }
            $bucket['typography']['line_height'] = $parsed;
            return true;
        }

        if ($property === 'text-align') {
            $bucket['typography']['text_align'] = $value;
            return true;
        }

        if ($property === 'text-transform') {
            $bucket['typography']['text_transform'] = $value;
            return true;
        }

        if ($property === 'text-decoration') {
            $bucket['typography']['style']['text_decoration'] = $value;
            return true;
        }

        if ($property === 'font-style') {
            $bucket['typography']['style']['font_style'] = $value;
            return true;
        }

        if ($property === 'letter-spacing') {
            $parsed = $this->toOxyLength($value, false, true);
            if ($parsed === null) {
                return false;
            }
            $bucket['typography']['letter_spacing'] = $parsed;
            return true;
        }

        if ($property === 'text-indent') {
            $parsed = $this->toOxyLength($value, false, true);
            if ($parsed === null) {
                return false;
            }
            $bucket['typography']['text_indent'] = $parsed;
            return true;
        }

        if ($property === 'direction') {
            $bucket['typography']['direction'] = $value;
            return true;
        }

        if ($property === 'overflow-wrap') {
            $bucket['typography']['overflow_wrap'] = $value;
            return true;
        }

        if ($property === 'text-wrap') {
            $bucket['typography']['text_wrap'] = $value;
            return true;
        }

        if ($property === 'text-overflow') {
            $bucket['typography']['text_overflow'] = $value;
            return true;
        }

        if ($property === 'list-style-type') {
            $bucket['typography']['list_style'] = $value;
            return true;
        }

        if ($property === '-webkit-text-stroke-width') {
            $parsed = $this->toOxyLength($value, false, true);
            if ($parsed === null) {
                return false;
            }
            $bucket['typography']['stroke']['stroke_width'] = $parsed;
            return true;
        }

        if ($property === '-webkit-text-stroke-color') {
            $bucket['typography']['stroke']['stroke_color'] = $value;
            return true;
        }

        if (str_starts_with($property, 'margin-')) {
            $side = substr($property, 7);
            $parsed = $this->toOxyLength($value);
            if ($parsed === null) {
                return false;
            }
            $bucket['spacing']['spacing']['margin'][$side] = $parsed;
            return true;
        }

        if (str_starts_with($property, 'padding-')) {
            $side = substr($property, 8);
            $parsed = $this->toOxyLength($value);
            if ($parsed === null) {
                return false;
            }
            $bucket['spacing']['spacing']['padding'][$side] = $parsed;
            return true;
        }

        if ($property === 'border-radius') {
            $parsed = $this->toOxyLength($value);
            if ($parsed === null) {
                return false;
            }
            $bucket['borders']['border_radius'] = [
                'all' => $parsed,
                'topLeft' => $parsed,
                'topRight' => $parsed,
                'bottomLeft' => $parsed,
                'bottomRight' => $parsed,
                'editMode' => 'all',
            ];
            return true;
        }

        if ($property === 'opacity') {
            $bucket['effects']['opacity'] = $this->toOxyOpacity($value);
            return true;
        }

        if ($property === 'cursor') {
            $bucket['effects']['cursor'] = $value;
            return true;
        }

        if ($property === 'mix-blend-mode') {
            $bucket['effects']['blend_mode'] = $value;
            return true;
        }

        if ($property === 'pointer-events') {
            $bucket['effects']['pointer_events'] = $value;
            return true;
        }

        if ($property === 'outline-style') {
            $bucket['effects']['outline_style'] = $value;
            return true;
        }

        if ($property === 'outline-width') {
            $parsed = $this->toOxyLength($value);
            if ($parsed === null) {
                return false;
            }
            $bucket['effects']['outline_width'] = $parsed;
            return true;
        }

        if ($property === 'outline-offset') {
            $parsed = $this->toOxyLength($value);
            if ($parsed === null) {
                return false;
            }
            $bucket['effects']['outline_offset'] = $parsed;
            return true;
        }

        if ($property === 'outline-color') {
            $bucket['effects']['outline_color'] = $value;
            return true;
        }

        return false;
    }

    private function expandBoxShorthand(string $value): ?array
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        $parts = array_values(array_filter($parts, static fn($v) => $v !== ''));

        return match (count($parts)) {
            1 => ['top' => $parts[0], 'right' => $parts[0], 'bottom' => $parts[0], 'left' => $parts[0]],
            2 => ['top' => $parts[0], 'right' => $parts[1], 'bottom' => $parts[0], 'left' => $parts[1]],
            3 => ['top' => $parts[0], 'right' => $parts[1], 'bottom' => $parts[2], 'left' => $parts[1]],
            4 => ['top' => $parts[0], 'right' => $parts[1], 'bottom' => $parts[2], 'left' => $parts[3]],
            default => null,
        };
    }

private function toOxyLength(string $value, bool $allowUnitlessCustom = false, bool $allowCustomExpression = true): ?array
{
    $value = trim($value);

    if ($value === '' || strtolower($value) === 'undefined' || strtolower($value) === 'null') {
        return null;
    }

    if ($value === '0' || $value === '0.0') {
        return [
            'number' => 0,
            'unit' => 'px',
            'style' => '0px',
        ];
    }

    if ($value === 'auto') {
        return [
            'number' => null,
            'unit' => 'auto',
            'style' => 'auto',
        ];
    }

    if (preg_match('/^(-?\d+(?:\.\d+)?)(px|em|rem|%|vh|vw|vmin|vmax|deg|ms|s)$/i', $value, $m)) {
        $number = str_contains($m[1], '.') ? (float) $m[1] : (int) $m[1];

        return [
            'number' => $number,
            'unit' => strtolower($m[2]),
            'style' => $value,
        ];
    }

    if ($allowUnitlessCustom && preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
        return [
            'number' => $value,
            'unit' => 'custom',
            'style' => $value,
        ];
    }

    if ($allowCustomExpression) {
        $clean = rtrim($value, ';');

        if ($clean === '' || strtolower($clean) === 'undefined') {
            return null;
        }

        return [
            'number' => $clean,
            'unit' => 'custom',
            'style' => $clean,
        ];
    }

    return null;
}

    private function toOxyOpacity(string $value): int|string
    {
        $value = trim($value);

        if (!is_numeric($value)) {
            return $value;
        }

        $num = (float) $value;

        if ($num <= 1) {
            return (int) round($num * 100);
        }

        return (int) round($num);
    }

    private function parseObjectPosition(string $value): ?array
    {
        $value = strtolower(trim($value));

        return match ($value) {
            'center' => ['x' => 50, 'y' => 50],
            'top' => ['x' => 50, 'y' => 0],
            'bottom' => ['x' => 50, 'y' => 100],
            'left' => ['x' => 0, 'y' => 50],
            'right' => ['x' => 100, 'y' => 50],
            'left top' => ['x' => 0, 'y' => 0],
            'left bottom' => ['x' => 0, 'y' => 100],
            'right top' => ['x' => 100, 'y' => 0],
            'right bottom' => ['x' => 100, 'y' => 100],
            default => $this->parsePercentPair($value),
        };
    }

    private function parsePercentPair(string $value): ?array
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];
        if (count($parts) !== 2) {
            return null;
        }

        if (
            preg_match('/^(-?\d+(?:\.\d+)?)%$/', $parts[0], $mx) &&
            preg_match('/^(-?\d+(?:\.\d+)?)%$/', $parts[1], $my)
        ) {
            return [
                'x' => (float) $mx[1],
                'y' => (float) $my[1],
            ];
        }

        return null;
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

new CTOI_Plugin();