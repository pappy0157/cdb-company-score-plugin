<?php
/**
 * Plugin Name: CDB Company Score & Chart
 * Description: 全国企業データベース（CDB）向けの企業スコアリングと可視化（レーダーチャート＋診断表示）機能を提供するWordPressプラグイン。企業投稿（post type=company を想定）にメタボックスで指標を入力し、スコアを自動計算。ショートコード [company_score_chart id="{post_id}"] でチャートと診断を表示。
 * Version: 1.2.0
 * Author: GlobalDreamJapan
 * License: GPL-2.0+
 */

if (!defined('ABSPATH')) exit;

class CDB_Company_Score_Plugin {
    const OPTION_KEY = 'cdb_company_score_settings';

    private $defaults = [
        'post_type' => 'company',
        // Weights (max points per sub-factor) ※合計=125（初期値）
        'weights' => [
            // 1) 基本透明性（最大55）
            'financials_published' => 20,   // 決算公告・EDINET等
            'contact_public'        => 10,   // 固定電話・代表メール等
            'employees_disclosed'   => 10,   // 従業員数公開（被保険者数含む）
            'representative_info'   => 5,    // 代表者名・肩書
            'address_verified'      => 10,   // 実在住所

            // 2) デジタルプレゼンス（最大25）
            'own_domain'            => 10,   // 公式サイト独自ドメイン
            'social_presence'       => 5,    // SNS/採用/PR ページ
            'pr_last12m'            => 5,    // 直近12ヶ月のPR実績
            'trusted_backlinks'     => 5,    // 信頼外部からの被リンク

            // 3) 信用・規模・実績（最大45）
            'corp_class'            => 20,   // 上場/大/中/中小/個人
            'age'                   => 10,   // 設立年数
            'capital'               => 10,   // 資本金
            'grants'                => 5,    // 補助金・官公庁採択歴
        ],
        // Risk penalties (max -40)
        'penalties' => [
            'virtual_address'       => -10,
            'no_fixed_line'         => -5,   // 固定回線なし（050等のみ）
            'compliance_issue'      => -20,  // 閉鎖/倒産/公告義務違反 等
            'name_industry_mismatch'=> -10,
            'ssl_or_domain_issue'   => -5,
        ],
        // Meta keys mapping（サイトに合わせて管理画面から変更可能）
        'metakeys' => [
            'financials_published' => '_financials_published', // bool
            'contact_public'       => '_contact_public',        // bool
            'employees_count'      => '_employees_count',       // int
            'representative_name'  => '_representative_name',   // string
            'address_type'         => '_address_type',          // real|rental|virtual
            'domain'               => '_domain',                // string (example: example.co.jp)
            'ssl_enabled'          => '_ssl_enabled',           // bool
            'domain_suspicious'    => '_domain_suspicious',     // bool
            'sns_links'            => '_sns_links',             // text (newline separated)
            'pr_last12m'           => '_pr_count_last12m',      // int
            'trusted_backlinks'    => '_trusted_backlinks',     // int
            'corp_class'           => '_corp_class',            // listed|large|mid|sme|sole
            'established_year'     => '_established_year',      // int (e.g., 2005)
            'capital_yen'          => '_capital_yen',           // int
            'grants_awarded'       => '_grants_awarded',        // int count
            'phone_has_fixed'      => '_phone_has_fixed',       // bool
            'phone_mobile_only'    => '_phone_mobile_only',     // bool
            'compliance_events'    => '_compliance_events',     // none|violation|closed|bankrupt|dormant
            'name_industry_mismatch' => '_name_industry_mismatch', // bool
        ],
    ];

    public function __construct() {
        add_action('init', [$this, 'register_assets']);
        add_shortcode('company_score_chart', [$this, 'shortcode_chart']);
        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('add_meta_boxes', [$this, 'add_metabox']);
        add_action('save_post', [$this, 'save_metabox']);
        register_activation_hook(__FILE__, [$this, 'on_activate']);
    }

    private function get_settings() {
        $saved = get_option(self::OPTION_KEY, []);
        return wp_parse_args($saved, $this->defaults);
    }

    public function on_activate() {
        if (!get_option(self::OPTION_KEY)) {
            add_option(self::OPTION_KEY, $this->defaults);
        }
    }

    public function register_assets() {
        // Chart.js CDN
        wp_register_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true);
        // CSS は wp_head で出力
    }

    /** Admin Settings **/
    public function register_settings_page() {
        add_options_page(
            'CDB Company Score',
            'CDB Company Score',
            'manage_options',
            'cdb-company-score',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, function($input){
            if (!is_array($input)) return $this->get_settings();
            if (isset($input['post_type'])) $input['post_type'] = sanitize_text_field($input['post_type']);
            foreach (['weights','penalties'] as $grp) {
                if (!isset($input[$grp]) || !is_array($input[$grp])) continue;
                foreach ($input[$grp] as $k=>$v) {
                    $input[$grp][$k] = is_numeric($v) ? floatval($v) : 0;
                }
            }
            if (isset($input['metakeys']) && is_array($input['metakeys'])) {
                foreach ($input['metakeys'] as $k=>$v) {
                    $input['metakeys'][$k] = sanitize_text_field($v);
                }
            }
            return wp_parse_args($input, $this->defaults);
        });
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $s = $this->get_settings();
        ?>
        <div class="wrap">
            <h1>CDB Company Score 設定</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_KEY); ?>
                <h2 class="title">基本</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">対象の投稿タイプ</th>
                        <td>
                            <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[post_type]" value="<?php echo esc_attr($s['post_type']); ?>" />
                            <p class="description">企業を管理しているカスタム投稿タイプ（例: company）</p>
                        </td>
                    </tr>
                </table>

                <h2 class="title">配点（Weights）</h2>
                <table class="form-table" role="presentation">
                    <?php foreach ($s['weights'] as $key=>$val): ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($key); ?></th>
                        <td>
                            <input type="number" step="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[weights][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($val); ?>" />
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <h2 class="title">減点（Penalties）</h2>
                <table class="form-table" role="presentation">
                    <?php foreach ($s['penalties'] as $key=>$val): ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($key); ?></th>
                        <td>
                            <input type="number" step="1" name="<?php echo esc_attr(self::OPTION_KEY); ?>[penalties][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($val); ?>" />
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <h2 class="title">メタキー（Meta keys mapping）</h2>
                <table class="form-table" role="presentation">
                    <?php foreach ($s['metakeys'] as $key=>$val): ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($key); ?></th>
                        <td>
                            <input type="text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[metakeys][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($val); ?>" class="regular-text" />
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /** Metabox for company details **/
    public function add_metabox() {
        $s = $this->get_settings();
        add_meta_box(
            'cdb_company_score_box',
            '会社スコア指標（CDB）',
            [$this, 'render_metabox'],
            $s['post_type'],
            'normal',
            'default'
        );
    }

    private function get_meta($post_id, $key, $default='') {
        $val = get_post_meta($post_id, $key, true);
        return $val === '' ? $default : $val;
    }

    public function render_metabox($post) {
        wp_nonce_field('cdb_company_score_save', 'cdb_company_score_nonce');
        $s = $this->get_settings();
        $m = $s['metakeys'];
        $vals = [];
        foreach ($m as $logical=>$meta_key) {
            $vals[$logical] = $this->get_meta($post->ID, $meta_key, '');
        }
        $checked = function($v){ return $v ? 'checked' : ''; };
        ?>
        <style>
            .cdb-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
            .cdb-grid .card{background:#fff;border:1px solid #ddd;padding:12px;border-radius:8px}
            .cdb-grid h3{margin:0 0 8px}
            .cdb-field{margin:8px 0}
        </style>
        <div class="cdb-grid">
            <div class="card">
                <h3>1) 基本透明性</h3>
                <div class="cdb-field">
                    <label><input type="checkbox" name="<?php echo esc_attr($m['financials_published']); ?>" value="1" <?php echo $checked($vals['financials_published']); ?>> 決算情報を公開（官報/EDINET/IR）</label>
                </div>
                <div class="cdb-field">
                    <label><input type="checkbox" name="<?php echo esc_attr($m['contact_public']); ?>" value="1" <?php echo $checked($vals['contact_public']); ?>> 連絡先（固定電話/代表メール/フォーム）を公開</label>
                </div>
                <div class="cdb-field">
                    <label>従業員数（被保険者数可）<br>
                        <input type="number" name="<?php echo esc_attr($m['employees_count']); ?>" value="<?php echo esc_attr($vals['employees_count']); ?>" min="0">
                    </label>
                </div>
                <div class="cdb-field">
                    <label>代表者名<br>
                        <input type="text" name="<?php echo esc_attr($m['representative_name']); ?>" value="<?php echo esc_attr($vals['representative_name']); ?>" class="regular-text">
                    </label>
                </div>
                <div class="cdb-field">
                    <label>住所タイプ
                        <select name="<?php echo esc_attr($m['address_type']); ?>">
                            <?php $opts=['real'=>'実在','rental'=>'レンタル','virtual'=>'バーチャル'];foreach($opts as $k=>$lbl){$sel=selected($vals['address_type'],$k,false);echo "<option value='$k' $sel>$lbl</option>";}?>
                        </select>
                    </label>
                </div>
            </div>
            <div class="card">
                <h3>2) デジタルプレゼンス</h3>
                <div class="cdb-field">
                    <label>公式ドメイン（例: example.co.jp）<br>
                        <input type="text" name="<?php echo esc_attr($m['domain']); ?>" value="<?php echo esc_attr($vals['domain']); ?>" class="regular-text">
                    </label>
                </div>
                <div class="cdb-field">
                    <label><input type="checkbox" name="<?php echo esc_attr($m['ssl_enabled']); ?>" value="1" <?php echo $checked($vals['ssl_enabled']); ?>> SSL（https）有効</label>
                </div>
                <div class="cdb-field">
                    <label><input type="checkbox" name="<?php echo esc_attr($m['domain_suspicious']); ?>" value="1" <?php echo $checked($vals['domain_suspicious']); ?>> ドメイン不審（無料/使い捨て など）</label>
                </div>
                <div class="cdb-field">
                    <label>SNS/採用/PRリンク（改行で複数）<br>
                        <textarea name="<?php echo esc_attr($m['sns_links']); ?>" rows="4" class="large-text"><?php echo esc_textarea($vals['sns_links']); ?></textarea>
                    </label>
                </div>
                <div class="cdb-field">
                    <label>直近12ヶ月のプレスリリース数<br>
                        <input type="number" name="<?php echo esc_attr($m['pr_last12m']); ?>" value="<?php echo esc_attr($vals['pr_last12m']); ?>" min="0">
                    </label>
                </div>
                <div class="cdb-field">
                    <label>信頼外部からの被リンク件数（目安）<br>
                        <input type="number" name="<?php echo esc_attr($m['trusted_backlinks']); ?>" value="<?php echo esc_attr($vals['trusted_backlinks']); ?>" min="0">
                    </label>
                </div>
            </div>
            <div class="card">
                <h3>3) 信用・規模・実績</h3>
                <div class="cdb-field">
                    <label>法人区分
                        <select name="<?php echo esc_attr($m['corp_class']); ?>">
                            <?php $opts=['listed'=>'上場','large'=>'大企業','mid'=>'中堅','sme'=>'中小','sole'=>'個人事業'];foreach($opts as $k=>$lbl){$sel=selected($vals['corp_class'],$k,false);echo "<option value='$k' $sel>$lbl</option>";}?>
                        </select>
                    </label>
                </div>
                <div class="cdb-field">
                    <label>設立年（西暦）<br>
                        <input type="number" name="<?php echo esc_attr($m['established_year']); ?>" value="<?php echo esc_attr($vals['established_year']); ?>" min="1800" max="<?php echo esc_attr(date('Y')); ?>">
                    </label>
                </div>
                <div class="cdb-field">
                    <label>資本金（円）<br>
                        <input type="number" name="<?php echo esc_attr($m['capital_yen']); ?>" value="<?php echo esc_attr($vals['capital_yen']); ?>" min="0" step="10000">
                    </label>
                </div>
                <div class="cdb-field">
                    <label>補助金・官公庁採択 回数<br>
                        <input type="number" name="<?php echo esc_attr($m['grants_awarded']); ?>" value="<?php echo esc_attr($vals['grants_awarded']); ?>" min="0">
                    </label>
                </div>
            </div>
            <div class="card">
                <h3>4) リスク・警告（減点）</h3>
                <div class="cdb-field">
                    <label><input type="checkbox" name="<?php echo esc_attr($m['phone_has_fixed']); ?>" value="1" <?php echo $checked($vals['phone_has_fixed']); ?>> 固定電話あり</label>
                </div>
                <div class="cdb-field">
                    <label><input type="checkbox" name="<?php echo esc_attr($m['phone_mobile_only']); ?>" value="1" <?php echo $checked($vals['phone_mobile_only']); ?>> 携帯/050のみ</label>
                </div>
                <div class="cdb-field">
                    <label>コンプライアンス事象
                        <select name="<?php echo esc_attr($m['compliance_events']); ?>">
                            <?php $opts=['none'=>'無し','violation'=>'公告違反等','closed'=>'閉鎖','bankrupt'=>'倒産','dormant'=>'休眠'];foreach($opts as $k=>$lbl){$sel=selected($vals['compliance_events'],$k,false);echo "<option value='$k' $sel>$lbl</option>";}?>
                        </select>
                    </label>
                </div>
                <div class="cdb-field">
                    <label><input type="checkbox" name="<?php echo esc_attr($m['name_industry_mismatch']); ?>" value="1" <?php echo $checked($vals['name_industry_mismatch']); ?>> 社名・業種の不一致/紛らわしさ</label>
                </div>
            </div>
        </div>
        <?php
    }

    public function save_metabox($post_id) {
        if (!isset($_POST['cdb_company_score_nonce']) || !wp_verify_nonce($_POST['cdb_company_score_nonce'], 'cdb_company_score_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        $s = $this->get_settings();
        foreach ($s['metakeys'] as $logical=>$meta_key) {
            if (isset($_POST[$meta_key])) {
                $val = $_POST[$meta_key];
                if (is_array($val)) $val = array_map('sanitize_text_field', $val);
                if ($meta_key === $s['metakeys']['sns_links']) {
                    $val = wp_kses_post($val);
                } else {
                    $val = is_string($val) ? sanitize_text_field($val) : $val;
                }
                update_post_meta($post_id, $meta_key, $val);
            } else {
                $checkboxes = [
                    'financials_published','contact_public','ssl_enabled','domain_suspicious','phone_has_fixed','phone_mobile_only','name_industry_mismatch'
                ];
                if (in_array($logical, $checkboxes, true)) {
                    delete_post_meta($post_id, $meta_key);
                }
            }
        }
    }

    /*** Utility ***/
    private function bool($v){ return $v === '1' || $v === 1 || $v === true || $v === 'true'; }
    private function has_own_domain($domain){
        if (!$domain) return false;
        return (bool) preg_match('/\.[a-z]{2,}$/i', $domain);
    }
    private function normalize_lines($text){
        $lines = array_filter(array_map('trim', preg_split('/\r?\n/', (string)$text)));
        return array_values($lines);
    }
    private function score_corp_class($cls, $max){
        switch ($cls){
            case 'listed': return $max;        // 100%
            case 'large':  return $max * 0.75; // 75%
            case 'mid':    return $max * 0.5;  // 50%
            case 'sme':    return $max * 0.25; // 25%
            case 'sole':   return 0;
            default:       return 0;
        }
    }
    private function grade($score){
        if ($score >= 90) return 'A+';
        if ($score >= 80) return 'A';
        if ($score >= 70) return 'B';
        if ($score >= 60) return 'C';
        if ($score >= 40) return 'D';
        return 'E';
    }

    /** Diagnostic helpers **/
    private function pct_class($section, $pct){
        // penalty は大きいほど悪化（他は大きいほど良化）
        if ($section === 'penalty'){
            if ($pct >= 75) return 'critical';
            if ($pct >= 50) return 'bad';
            if ($pct >= 25) return 'warn';
            if ($pct > 0)   return 'ok';
            return 'good';
        } else {
            if ($pct >= 80) return 'good';
            if ($pct >= 60) return 'ok';
            if ($pct >= 40) return 'warn';
            if ($pct >= 20) return 'bad';
            return 'critical';
        }
    }
    private function state_icon($cls){
        // 視覚補助アイコン（絵文字）。必要に応じて画像やSVGに差し替え可能
        switch ($cls){
            case 'good':     return '✅';
            case 'ok':       return '🟢';
            case 'warn':     return '⚠️';
            case 'bad':      return '🟠';
            case 'critical': return '⛔';
            default:         return '';
        }
    }
    private function diagnostic_text($section, $pct, $sc){
        $t = '';
        switch($section){
            case 'basic':
                if ($pct >= 80) $t = '基礎情報の公開度は非常に高く、第三者から見ても信頼性が高い状態です。公開範囲の維持と更新頻度の担保を推奨します。';
                elseif ($pct >= 60) $t = '概ね十分です。従業員数の明記や代表者肩書の補足、実在住所の確認リンク追加で更なる向上が見込めます。';
                elseif ($pct >= 40) $t = '一定の公開はあるものの不足があります。決算公告や固定電話の明記、代表者情報を追加しましょう。';
                elseif ($pct >= 20) $t = '公開情報が少なく信頼判断が難しい状態です。決算情報・実在住所・連絡先の整備を優先してください。';
                else $t = '基礎情報がほぼ未整備です。官報/EDINET掲載、代表者・住所・連絡手段の公開から着手しましょう。';
                break;
            case 'digital':
                if ($pct >= 80) $t = 'ドメイン・SNS・PRの整備が進んでおり、発信力が高いです。専門メディアや業界団体からの被リンクを増やすと更に良くなります。';
                elseif ($pct >= 60) $t = '基本は整っています。直近12ヶ月のPR頻度を上げ、採用/IRページの充実やリンクの整合性強化を行いましょう。';
                elseif ($pct >= 40) $t = '発信の土台はあるものの継続性が不足。公式SNSの運用開始・PR配信の定期化・独自ドメインの取得を検討してください。';
                elseif ($pct >= 20) $t = 'デジタル上の存在感が希薄です。公式サイトの整備・https化・最低限のSNS開設から着手しましょう。';
                else $t = '公式サイト/ドメイン/SNSが未整備です。ブランドの信用形成のため最優先で構築が必要です。';
                break;
            case 'credit':
                if ($pct >= 80) $t = '規模・実績ともに強く、長期継続力が評価されます。補助金採択事例や資本政策の開示で更に盤石に。';
                elseif ($pct >= 60) $t = '十分な信用力があります。資本金・年次の訴求、実績の可視化（事例/導入先）でスコア向上が見込めます。';
                elseif ($pct >= 40) $t = '成長途上です。設立年の明示、資本金水準の開示、外部採択実績の取得・掲載を進めましょう。';
                elseif ($pct >= 20) $t = '規模・実績の情報が乏しく判断が難しい状態。実績の収集・公的採択の獲得/公表を目標に。';
                else $t = '信用・規模の裏付けが弱いです。資本構成や沿革の公開、信頼できる第三者評価の獲得を急ぎましょう。';
                break;
            case 'penalty':
                if ($pct == 0) $t = '特段のリスクは検知されていません。現状維持で問題ありません。';
                elseif ($pct <= 25) $t = '軽微なリスクが見られます（例：SSL期限切れ等）。早期に是正しましょう。';
                elseif ($pct <= 50) $t = '中程度のリスクがあります（例：固定回線なし、ドメインの信頼性低下）。改善計画の策定を推奨します。';
                elseif ($pct <= 75) $t = '高いリスクが検知されています（例：バーチャル住所、違反・休眠情報）。至急の是正が必要です。';
                else $t = '重大なリスクが想定されます（倒産・閉鎖・公告義務違反等）。事業継続性の見直しと公的情報の整合確認が必要です。';
                break;
            case 'overall':
                if ($pct >= 90) $t = '総合的に非常に強い体制です。発信の継続・第三者評価の追加でトップランクを維持しましょう。';
                elseif ($pct >= 80) $t = '高い信頼水準です。弱点の局所改善（例：被リンク質、採択事例）で更に安定化します。';
                elseif ($pct >= 60) $t = '標準以上ですが、基礎情報の充実とデジタル発信の継続でAランクが狙えます。';
                elseif ($pct >= 40) $t = '改善余地が大きい段階です。まずは基本透明性とSSL/連絡先整備を優先してください。';
                else $t = '信用形成の初期段階です。公開情報の整備・公式基盤構築・リスク是正を計画立てて実行しましょう。';
                break;
        }
        return $t;
    }

    /*** Scoring Engine ***/
    private function calc_scores($post_id) {
        $s = $this->get_settings();
        $m = $s['metakeys'];
        $w = $s['weights'];
        $p = $s['penalties'];

        // Fetch values
        $financials_published = $this->bool(get_post_meta($post_id, $m['financials_published'], true));
        $contact_public       = $this->bool(get_post_meta($post_id, $m['contact_public'], true));
        $employees_count      = intval(get_post_meta($post_id, $m['employees_count'], true));
        $representative_name  = trim(get_post_meta($post_id, $m['representative_name'], true));
        $address_type         = get_post_meta($post_id, $m['address_type'], true);

        $domain               = trim(get_post_meta($post_id, $m['domain'], true));
        $ssl_enabled          = $this->bool(get_post_meta($post_id, $m['ssl_enabled'], true));
        $domain_suspicious    = $this->bool(get_post_meta($post_id, $m['domain_suspicious'], true));
        $sns_links_raw        = get_post_meta($post_id, $m['sns_links'], true);
        $pr_last12m           = max(0, intval(get_post_meta($post_id, $m['pr_last12m'], true)));
        $trusted_backlinks    = max(0, intval(get_post_meta($post_id, $m['trusted_backlinks'], true)));

        $corp_class           = get_post_meta($post_id, $m['corp_class'], true);
        $established_year     = intval(get_post_meta($post_id, $m['established_year'], true));
        $capital_yen          = max(0, intval(get_post_meta($post_id, $m['capital_yen'], true)));
        $grants_awarded       = max(0, intval(get_post_meta($post_id, $m['grants_awarded'], true)));

        $phone_has_fixed      = $this->bool(get_post_meta($post_id, $m['phone_has_fixed'], true));
        $phone_mobile_only    = $this->bool(get_post_meta($post_id, $m['phone_mobile_only'], true));
        $compliance_events    = get_post_meta($post_id, $m['compliance_events'], true);
        $name_industry_mismatch = $this->bool(get_post_meta($post_id, $m['name_industry_mismatch'], true));

        // === Section maximums for normalization ===
        $basic_max   = $w['financials_published'] + $w['contact_public'] + $w['employees_disclosed'] + $w['representative_info'] + $w['address_verified'];
        $digital_max = $w['own_domain'] + $w['social_presence'] + $w['pr_last12m'] + $w['trusted_backlinks'];
        $credit_max  = $w['corp_class'] + $w['age'] + $w['capital'] + $w['grants'];
        $pen_max     = abs($p['virtual_address']) + abs($p['no_fixed_line']) + abs($p['compliance_issue']) + abs($p['name_industry_mismatch']) + abs($p['ssl_or_domain_issue']);

        // 1) Basic Transparency
        $basic = 0;
        if ($financials_published) $basic += $w['financials_published'];
        if ($contact_public)       $basic += $w['contact_public'];
        if ($employees_count > 0) {
            // scale: 1-20=25%, 21-99=50%, 100+=100%
            $portion = ($employees_count >= 100) ? 1 : (($employees_count >= 21) ? 0.5 : 0.25);
            $basic += $w['employees_disclosed'] * $portion;
        }
        if ($representative_name !== '') $basic += $w['representative_info'];
        if ($address_type === 'real') $basic += $w['address_verified'];

        // 2) Digital Presence
        $digital = 0;
        $own_domain_ok = $this->has_own_domain($domain);
        if ($own_domain_ok) $digital += $w['own_domain'];
        $sns_links = $this->normalize_lines($sns_links_raw);
        if (count($sns_links) >= 1) $digital += $w['social_presence'];
        if ($pr_last12m > 0) {
            // scale up to 5 releases -> full points
            $portion = min(1, $pr_last12m / 5.0);
            $digital += $w['pr_last12m'] * $portion;
        }
        if ($trusted_backlinks > 0) {
            // scale up to 10 trusted backlinks -> full points
            $portion = min(1, $trusted_backlinks / 10.0);
            $digital += $w['trusted_backlinks'] * $portion;
        }

        // 3) Credit/Scale/Track
        $credit = 0;
        $credit += $this->score_corp_class($corp_class, $w['corp_class']);
        if ($established_year > 0) {
            $age = max(0, intval(date('Y')) - $established_year);
            if ($age >= 10) $credit += $w['age'];
            elseif ($age >= 5) $credit += $w['age'] * 0.5;
            elseif ($age >= 1) $credit += $w['age'] * 0.3;
        }
        if ($capital_yen > 0) {
            if ($capital_yen >= 100000000) $credit += $w['capital'];
            elseif ($capital_yen >= 10000000) $credit += $w['capital'] * 0.7;
            elseif ($capital_yen >= 1000000) $credit += $w['capital'] * 0.5;
            else $credit += $w['capital'] * 0.2;
        }
        if ($grants_awarded > 0) {
            $portion = min(1, $grants_awarded / 2.0); // 2回で満点
            $credit += $w['grants'] * $portion;
        }

        // 4) Penalties
        $pen = 0;
        if ($address_type === 'virtual') $pen += $p['virtual_address'];
        if (!$phone_has_fixed && $phone_mobile_only) $pen += $p['no_fixed_line'];
        if (in_array($compliance_events, ['violation','closed','bankrupt','dormant'], true)) $pen += $p['compliance_issue'];
        if ($name_industry_mismatch) $pen += $p['name_industry_mismatch'];
        if (!$ssl_enabled || $domain_suspicious) $pen += $p['ssl_or_domain_issue'];

        // Aggregate & normalize 0-100
        $max_positive = array_sum($w); // default 125
        $raw = max(0, $basic + $digital + $credit + $pen); // penalties negative
        $score100 = ($max_positive > 0) ? round(min(100, ($raw / $max_positive) * 100), 1) : 0;
        $grade = $this->grade($score100);

        // Normalized percentages per section (0-100) for diagnostics
        $basic_pct   = $basic_max   > 0 ? round(($basic / $basic_max) * 100, 1) : 0;
        $digital_pct = $digital_max > 0 ? round(($digital / $digital_max) * 100, 1) : 0;
        $credit_pct  = $credit_max  > 0 ? round(($credit / $credit_max) * 100, 1) : 0;
        $pen_pct     = $pen_max     > 0 ? round((min(100, (abs($pen) / $pen_max) * 100)), 1) : 0;

        return [
            'basic'   => round($basic,1),
            'digital' => round($digital,1),
            'credit'  => round($credit,1),
            'penalty' => round($pen,1),
            'raw'     => round($raw,1),
            'score'   => $score100,
            'grade'   => $grade,
            'labels'  => ['基本透明性','デジタル発信','信用・規模','（減点）'],
            'dataset' => [$basic, $digital, $credit, abs($pen)],
            'pct'     => [
                'basic'=>$basic_pct, 'digital'=>$digital_pct, 'credit'=>$credit_pct, 'penalty'=>$pen_pct, 'overall'=>$score100
            ],
            'max'     => [
                'basic'=>$basic_max, 'digital'=>$digital_max, 'credit'=>$credit_max, 'penalty'=>$pen_max, 'positive'=>$max_positive
            ],
        ];
    }

    /** Shortcode Renderer **/
    public function shortcode_chart($atts){
        wp_enqueue_script('chartjs');

        $atts = shortcode_atts([
            'id' => get_the_ID(),
            'title' => '企業スコア',
        ], $atts, 'company_score_chart');
        $post_id = intval($atts['id']);
        if (!$post_id) return '<div class="zdb-score zdb-error">IDが無効です。</div>';

        $sc = $this->calc_scores($post_id);
        $uid = 'cdbChart_' . $post_id . '_' . wp_generate_password(6, false, false);

        ob_start();
        ?>
        <div class="zdb-score">
            <div class="zdb-score-header">
                <h3><?php echo esc_html($atts['title']); ?> <small>(総合: <?php echo esc_html($sc['score']); ?> / 100 ・ ランク: <?php echo esc_html($sc['grade']); ?>)</small></h3>
            </div>
            <div class="zdb-score-body">
                <div class="zdb-score-left">
                    <canvas id="<?php echo esc_attr($uid); ?>" width="380" height="380"></canvas>
                </div>
                <div class="zdb-score-right">
                    <table class="zdb-table">
                        <tbody>
                            <tr><th>基本透明性</th><td><?php echo esc_html($sc['basic']); ?></td></tr>
                            <tr><th>デジタル発信</th><td><?php echo esc_html($sc['digital']); ?></td></tr>
                            <tr><th>信用・規模</th><td><?php echo esc_html($sc['credit']); ?></td></tr>
                            <tr><th>減点合計</th><td><?php echo esc_html($sc['penalty']); ?></td></tr>
                            <tr class="zdb-total"><th>総合スコア</th><td><strong><?php echo esc_html($sc['score']); ?></strong></td></tr>
                            <tr class="zdb-grade"><th>ランク</th><td><strong><?php echo esc_html($sc['grade']); ?></strong></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="zdb-diagnostics">
                <h4>診断（詳細）</h4>
                <div class="zdb-dx-grid">
                    <?php
                    $card = function($title, $pctKey, $sectionKey, $isPenalty=false) use ($sc){
                        $pct  = $sc['pct'][$pctKey];
                        $cls  = ($isPenalty)
                            ? (($pct >= 75)?'critical':(($pct >= 50)?'bad':(($pct >= 25)?'warn':(($pct>0)?'ok':'good'))))
                            : (($pct >= 80)?'good':(($pct >= 60)?'ok':(($pct >= 40)?'warn':(($pct >= 20)?'bad':'critical'))));
                        $icon = CDB_Company_Score_Plugin::state_icon($cls);
                        ?>
                        <div class="dx-card <?php echo esc_attr($cls); ?>">
                            <div class="dx-head">
                                <span class="dx-title"><?php echo esc_html($icon.' '.$title); ?></span>
                                <span class="dx-badge"><?php echo esc_html($pct); ?>％</span>
                            </div>
                            <div class="dx-bar <?php echo $isPenalty ? 'invert' : ''; ?>"><span style="width: <?php echo esc_attr($pct); ?>%"></span></div>
                            <p class="dx-text"><?php echo esc_html(CDB_Company_Score_Plugin::diagnostic_text($sectionKey, $pct, $sc)); ?></p>
                        </div>
                        <?php
                    };
                    $card('基本透明性','basic','basic');
                    $card('デジタル発信','digital','digital');
                    $card('信用・規模','credit','credit');
                    $card('減点合計','penalty','penalty',true);
                    ?>
                    <?php
                        $overallCls = $this->pct_class('overall', $sc['pct']['overall']);
                        $overallIcon = $this->state_icon($overallCls);
                    ?>
                    <div class="dx-card wide <?php echo esc_attr($overallCls); ?>">
                        <div class="dx-head">
                            <span class="dx-title"><?php echo esc_html($overallIcon.' 総合スコア（'.$sc['grade'].'）'); ?></span>
                            <span class="dx-badge"><?php echo esc_html($sc['score']); ?> / 100</span>
                        </div>
                        <div class="dx-bar"><span style="width: <?php echo esc_attr($sc['pct']['overall']); ?>%"></span></div>
                        <p class="dx-text"><?php echo esc_html($this->diagnostic_text('overall', $sc['pct']['overall'], $sc)); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
        $inline_js = "(function(){\n            function boot(){\n                if (typeof Chart === 'undefined'){ setTimeout(boot, 50); return; }\n                var ctx = document.getElementById('" . esc_js($uid) . "').getContext('2d');\n                var data = {\n                    labels: " . wp_json_encode($sc['labels']) . ",\n                    datasets: [{ label: 'CDB Score', data: " . wp_json_encode($sc['dataset']) . ", fill: true, pointRadius: 3, tension: 0.2 }]\n                };\n                new Chart(ctx, { type: 'radar', data: data, options: { responsive: true, plugins: { legend: { display: false } }, scales: { r: { beginAtZero: true, angleLines: { display: true }, grid: { display: true } } } } });\n            }\n            if (document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', boot); } else { boot(); }\n        })();";
        wp_add_inline_script('chartjs', $inline_js, 'after');
        return ob_get_clean();
    }
}

new CDB_Company_Score_Plugin();

/** CSS 組み込み（診断カードデザイン含む） */
add_action('wp_head', function(){
    echo '<style>
/* ====== CDB Company Score – polished UI ====== */
.zdb-score{border:1px solid #e5e7eb;border-radius:16px;padding:18px;margin:18px 0;background:#ffffff;box-shadow: 0 8px 24px rgba(17,24,39,.06)}
.zdb-score-header h3{margin:0 0 14px;font-size:20px;letter-spacing:.2px;color:#0f172a}
.zdb-score-body{display:flex;gap:18px;flex-wrap:wrap}
.zdb-score-left{flex:1 1 360px;min-width:300px}
.zdb-score-right{flex:1 1 280px;min-width:260px}
.zdb-table{width:100%;border-collapse:separate;border-spacing:0 8px}
.zdb-table th{text-align:left;color:#334155;font-weight:700;font-size:13px}
.zdb-table td{text-align:right;font-weight:700;color:#0f172a}
.zdb-table .zdb-total td,.zdb-table .zdb-total th{border-top:1px dashed #e5e7eb;padding-top:10px}
.zdb-table .zdb-grade td{font-size:18px}
.zdb-diagnostics{margin-top:18px;padding-top:18px;border-top:1px dashed #e5e7eb}
.zdb-diagnostics h4{margin:0 0 14px;font-size:16px;line-height:1.2;color:#0f172a;font-weight:800;letter-spacing:.3px}
.zdb-dx-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}
.dx-card{position:relative;background:linear-gradient(180deg,#ffffff 0%,#fbfbff 100%);border:1px solid #eef2ff;border-radius:14px;padding:14px;box-shadow:0 4px 16px rgba(59,130,246,.06);transition:transform .15s ease, box-shadow .15s ease}
.dx-card:hover{transform:translateY(-1px);box-shadow:0 10px 28px rgba(59,130,246,.10)}
.dx-card.wide{grid-column:1/-1}
.dx-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.dx-title{font-size:13px;font-weight:800;color:#1e293b;display:flex;align-items:center;gap:8px}
.dx-title:before{content:"";display:inline-block;width:8px;height:8px;border-radius:50%;background:linear-gradient(135deg,#60a5fa 0%,#34d399 100%);box-shadow:0 0 0 3px rgba(99,102,241,.10)}
.dx-badge{font-size:12px;font-weight:800;color:#0f172a;background:#f8fafc;border:1px solid #e2e8f0;padding:4px 8px;border-radius:999px;line-height:1}
.dx-bar{position:relative;height:8px;background:#f1f5f9;border-radius:999px;overflow:hidden;margin:6px 0 8px}
.dx-bar span{position:absolute;left:0;top:0;bottom:0;width:0%;background:linear-gradient(90deg,#22c55e 0%,#10b981 50%,#06b6d4 100%);border-radius:999px;box-shadow:inset 0 0 0 1px rgba(15,23,42,.06),0 1px 6px rgba(2,132,199,.25)}
.dx-bar.invert span{background:linear-gradient(90deg,#f59e0b 0%,#ef4444 60%,#dc2626 100%)}
.dx-text{margin:0;font-size:13px;line-height:1.7;color:#1f2937}
.dx-card.good{border-color:#c7f9e5; box-shadow:0 4px 16px rgba(16,185,129,.08)}
.dx-card.ok{border-color:#dbeafe}
.dx-card.warn{border-color:#fde68a}
.dx-card.bad{border-color:#fecaca}
.dx-card.critical{border-color:#fca5a5; box-shadow:0 4px 16px rgba(239,68,68,.10)}
@media (max-width:480px){.zdb-score{padding:14px;border-radius:14px}.dx-title{font-size:12px}.dx-text{font-size:12.5px}}
</style>';
});
