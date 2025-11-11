<?php
/**
 * Plugin Name: CDB Company Score & Chart
 * Description: 全国企業データベース（CDB）向けの企業スコアリングと可視化（レーダーチャート＋診断表示）機能を提供するWordPressプラグイン。企業投稿（post type=company を想定）にメタボックスで指標を入力し、スコアを自動計算。ショートコード [company_score_chart id="{post_id}"] でチャートと診断を表示。
 * Version: 1.5.9
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
                            <?php $opts=['none'=>'無し','violation'=>'違反あり','closed'=>'閉鎖','bankrupt'=>'倒産','dormant'=>'休眠'];foreach($opts as $k=>$lbl){$sel=selected($vals['compliance_events'],$k,false);echo "<option value='$k' $sel>$lbl</option>";}?>
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
    private function label_corp_class($cls){
        switch ($cls){
            case 'listed': return '上場';
            case 'large':  return '大企業';
            case 'mid':    return '中堅';
            case 'sme':    return '中小';
            case 'sole':   return '個人事業';
            default:       return '未入力';
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
                $bx = isset($sc['extra']['basic']) ? $sc['extra']['basic'] : [];
                $pub   = !empty($bx['financials_published']) ? '公開あり' : '未公開';
                $cntct = !empty($bx['contact_public']) ? '公開あり' : '未公開';
                $emp   = (isset($bx['employees_count']) && $bx['employees_count']!=='') ? number_format((int)$bx['employees_count']).'名' : '未入力';
                $rep   = !empty($bx['representative_name']) ? $bx['representative_name'] : '未入力';
                $addr  = ($bx['address_type']==='real')?'実在':(($bx['address_type']==='rental')?'レンタル':'バーチャル/未入力');

                if ($pct >= 80) $t = '基礎情報の公開度は非常に高く、第三者から見ても信頼性が高い状態です。公開範囲の維持と更新頻度の担保を推奨します。';
                elseif ($pct >= 60) $t = '概ね十分です。従業員数の明記や代表者肩書の補足、実在住所の確認リンク追加で更なる向上が見込めます。';
                elseif ($pct >= 40) $t = '一定の公開はあるものの不足があります。決算公告や固定電話の明記、代表者情報を追加しましょう。';
                elseif ($pct >= 20) $t = '公開情報が少なく信頼判断が難しい状態です。決算情報・実在住所・連絡先の整備を優先してください。';
                else $t = '基礎情報がほぼ未整備です。官報/EDINET掲載、代表者・住所・連絡手段の公開から着手しましょう。';

                $detail = '<ul class="dx-list">'
                    .'<li>決算情報：'.esc_html($pub).'</li>'
                    .'<li>連絡先公開：'.esc_html($cntct).'</li>'
                    .'<li>従業員数(被保険者数)：'.esc_html($emp).'</li>'
                    .'<li>代表者：'.esc_html($rep).'</li>'
                    .'<li>住所：'.esc_html($addr).'</li>'
                    .'</ul>';
                if ($pct < 60) {
                    $detail .= '<div class="dx-tips"><strong>改善提案：</strong>官報/EDINETやIRでの決算公開、固定電話・代表メールの明記、実在住所の表記/証跡リンクを整備しましょう。</div>';
                }
                $t = '<div class="dx-paragraph">'.esc_html($t).'</div>'.$detail;
                break;
            case 'digital':
                $dx = isset($sc['extra']['digital']) ? $sc['extra']['digital'] : [];
                $domain = !empty($dx['domain']) ? $dx['domain'] : '未入力';
                $own    = !empty($dx['own_domain_ok']) ? '独自ドメイン' : '不明/未設定';
                $ssl    = !empty($dx['ssl_enabled']) ? '有効（https）' : '未対応';
                $sus    = !empty($dx['domain_suspicious']) ? '要注意' : '問題なし';
                $sns    = (isset($dx['sns_count']) && $dx['sns_count']!=='') ? intval($dx['sns_count']).'件' : '未入力';
                $prc    = isset($dx['pr_last12m']) ? intval($dx['pr_last12m']).'本' : '未入力';
                $backs  = isset($dx['trusted_backlinks']) ? intval($dx['trusted_backlinks']).'件' : '未入力';

                if ($pct >= 80) $t = 'ドメイン・SNS・PRの整備が進んでおり、発信力が高いです。専門メディアや業界団体からの被リンクを増やすと更に良くなります。';
                elseif ($pct >= 60) $t = '基本は整っています。直近12ヶ月のPR頻度を上げ、採用/IRページの充実やリンクの整合性強化を行いましょう。';
                elseif ($pct >= 40) $t = '発信の土台はあるものの継続性が不足。公式SNSの運用開始・PR配信の定期化・独自ドメインの取得を検討してください。';
                elseif ($pct >= 20) $t = 'デジタル上の存在感が希薄です。公式サイトの整備・https化・最低限のSNS開設から着手しましょう。';
                else $t = '公式サイト/ドメイン/SNSが未整備です。ブランドの信用形成のため最優先で構築が必要です。';

                $detail = '<ul class="dx-list">'
                    .'<li>ドメイン：'.esc_html($domain).'（'.esc_html($own).'）</li>'
                    .'<li>SSL：'.esc_html($ssl).'</li>'
                    .'<li>ドメイン健全性：'.esc_html($sus).'</li>'
                    .'<li>SNS/求人情報：'.esc_html($sns).'</li>'
                    .'<li>PR（直近12ヶ月）：'.esc_html($prc).'</li>'
                    .'<li>信頼被リンク目安：'.esc_html($backs).'</li>'
                    .'</ul>';
                if ($pct < 60) {
                    $detail .= '<div class="dx-tips"><strong>改善提案：</strong>独自ドメイン/httpsの整備、公式SNSの開設と運用、毎月のPR配信、専門メディアからの質の高い被リンク獲得を進めましょう。</div>';
                }
                $t = '<div class="dx-paragraph">'.esc_html($t).'</div>'.$detail;
                break;
            case 'credit':
                $cc = isset($sc['extra']['credit']) ? $sc['extra']['credit'] : [];
                $cls_label = $this->label_corp_class(isset($cc['corp_class'])?$cc['corp_class']:'');
                $age = isset($cc['age']) && $cc['age']>0 ? intval($cc['age']).'年' : '未入力';
                $ey  = !empty($cc['established_year']) ? intval($cc['established_year']).'年' : '未入力';
                $cap = isset($cc['capital_yen']) && $cc['capital_yen']>0 ? number_format(intval($cc['capital_yen'])).'円' : '未入力';
                $gr  = isset($cc['grants_awarded']) ? intval($cc['grants_awarded']).'件' : '未入力';

                if ($pct >= 80) $t = '規模・実績ともに強く、長期継続力が評価されます。補助金採択事例や資本政策の開示で更に盤石に。';
                elseif ($pct >= 60) $t = '十分な信用力があります。資本金・年次の訴求、実績の可視化（事例/導入先）でスコア向上が見込めます。';
                elseif ($pct >= 40) $t = '成長途上です。設立年の明示、資本金水準の開示、外部採択実績の取得・掲載を進めましょう。';
                elseif ($pct >= 20) $t = '規模・実績の情報が乏しく判断が難しい状態。実績の収集・公的採択の獲得/公表を目標に。';
                else $t = '信用・規模の裏付けが弱いです。資本構成や沿革の公開、信頼できる第三者評価の獲得を急ぎましょう。';

                $detail = '<ul class="dx-list">'
                    .'<li>法人区分：'.esc_html($cls_label).'</li>'
                    .'<li>設立：'.esc_html($ey).'（創業'.esc_html($age).'）</li>'
                    .'<li>資本金：'.esc_html($cap).'</li>'
                    .'<li>公的採択：'.esc_html($gr).'</li>'
                    .'</ul>';

                if ($pct < 60) {
                    $detail .= '<div class="dx-tips"><strong>改善提案：</strong>沿革・資本金の開示充実、採択実績の獲得と掲載、主要導入事例の明文化を推奨します。</div>';
                }
                $t = '<div class="dx-paragraph">'.esc_html($t).'</div>'.$detail;
                break;
            case 'penalty':
                $px = isset($sc['extra']['penalty']) ? $sc['extra']['penalty'] : [];
                $addr  = ($px['address_type']==='virtual')?'バーチャル':(($px['address_type']==='rental')?'レンタル':'実在/未入力');
                $phone = !empty($px['phone_has_fixed']) ? '固定回線あり' : (!empty($px['phone_mobile_only']) ? '携帯/050のみ' : '未入力');
                $comp  = !empty($px['compliance_events']) ? $px['compliance_events'] : 'none';
                $comp_label = [ 'none'=>'無し','violation'=>'違反あり','closed'=>'閉鎖','bankrupt'=>'倒産','dormant'=>'休眠' ];
                $compTxt = isset($comp_label[$comp]) ? $comp_label[$comp] : '未入力';
                $nameMis = !empty($px['name_industry_mismatch']) ? 'あり' : '無し';
                $ssl    = !empty($px['ssl_enabled']) ? 'OK' : 'NG';
                $sus    = !empty($px['domain_suspicious']) ? '要注意' : 'OK';

                if ($pct == 0) $t = '特段のリスクは検知されていません。現状維持で問題ありません。';
                elseif ($pct <= 25) $t = '軽微なリスクが見られます（例：SSL期限切れ等）。早期に是正しましょう。';
                elseif ($pct <= 50) $t = '中程度のリスクがあります（例：固定回線なし、ドメインの信頼性低下）。改善計画の策定を推奨します。';
                elseif ($pct <= 75) $t = '高いリスクが検知されています（例：バーチャル住所、違反・休眠情報）。至急の是正が必要です。';
                else $t = '重大なリスクが想定されます（倒産・閉鎖・公告義務違反等）。事業継続性の見直しと公的情報の整合確認が必要です。';

                $detail = '<ul class="dx-list">'
                    .'<li>住所種別：'.esc_html($addr).'</li>'
                    .'<li>電話：'.esc_html($phone).'</li>'
                    .'<li>コンプライアンス：'.esc_html($compTxt).'</li>'
                    .'<li>社名/業種の不一致：'.esc_html($nameMis).'</li>'
                    .'<li>SSL：'.esc_html($ssl).' / ドメイン健全性：'.esc_html($sus).'</li>'
                    .'</ul>';
                if ($pct >= 25) {
                    $detail .= '<div class="dx-tips"><strong>改善提案：</strong>固定回線の明記、実在住所の記載と証跡整備、SSL/ドメインの健全化、名称表記の整合を早急に対応してください。</div>';
                }
                $t = '<div class="dx-paragraph">'.esc_html($t).'</div>'.$detail;
                break;
            case 'overall':
                $ox = isset($sc['extra']['overall']) ? $sc['extra']['overall'] : [];
                $score = isset($ox['score']) ? $ox['score'] : $pct;
                $grade = isset($ox['grade']) ? $ox['grade'] : $this->grade($score);
                if ($pct >= 90) $t = '総合的に非常に強い体制です。発信の継続・第三者評価の追加でトップランクを維持しましょう。';
                elseif ($pct >= 80) $t = '高い信頼水準です。弱点の局所改善（例：被リンク質、採択事例）で更に安定化します。';
                elseif ($pct >= 60) $t = '標準以上ですが、基礎情報の充実とデジタル発信の継続でAランクが狙えます。';
                elseif ($pct >= 40) $t = '改善余地が大きい段階です。まずは基本透明性とSSL/連絡先整備を優先してください。';
                else $t = '信用形成の初期段階です。公開情報の整備・公式基盤構築・リスク是正を計画立てて実行しましょう。';

                $detail = '<ul class="dx-list">'
                    .'<li>総合スコア：'.esc_html($score).' / 100</li>'
                    .'<li>ランク：'.esc_html($grade).'</li>'
                    .'</ul>';
                if ($pct < 80) {
                    $detail .= '<div class="dx-tips"><strong>全体方針：</strong>「基本透明性の強化 → デジタル発信の継続 → リスク是正」の順で着手すると効率よくスコアを改善できます。</div>';
                }
                $t = '<div class="dx-paragraph">'.esc_html($t).'</div>'.$detail;
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
            $portion = min(1, $pr_last12m / 5.0);
            $digital += $w['pr_last12m'] * $portion;
        }
        if ($trusted_backlinks > 0) {
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

        // Normalized percentages per section (0-100)
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
            'labels'  => ['基本透明性','デジタル発信','信用・規模','総合スコア'],
            'dataset' => [$basic_pct, $digital_pct, $credit_pct, $score100],
            'pct'     => [
                'basic'=>$basic_pct, 'digital'=>$digital_pct, 'credit'=>$credit_pct, 'penalty'=>$pen_pct, 'overall'=>$score100
            ],
            'max'     => [
                'basic'=>$basic_max, 'digital'=>$digital_max, 'credit'=>$credit_max, 'penalty'=>$pen_max, 'positive'=>$max_positive
            ],
            'extra'   => [
                'basic' => [
                    'financials_published' => $financials_published,
                    'contact_public'       => $contact_public,
                    'employees_count'      => $employees_count,
                    'representative_name'  => $representative_name,
                    'address_type'         => $address_type,
                ],
                'digital' => [
                    'domain'            => $domain,
                    'own_domain_ok'     => $own_domain_ok,
                    'ssl_enabled'       => $ssl_enabled,
                    'domain_suspicious' => $domain_suspicious,
                    'sns_count'         => count($sns_links),
                    'pr_last12m'        => $pr_last12m,
                    'trusted_backlinks' => $trusted_backlinks,
                ],
                'credit' => [
                    'corp_class'        => $corp_class,
                    'age'               => isset($age)?$age:0,
                    'established_year'  => $established_year,
                    'capital_yen'       => $capital_yen,
                    'grants_awarded'    => $grants_awarded,
                ],
                'penalty' => [
                    'address_type'          => $address_type,
                    'phone_has_fixed'       => $phone_has_fixed,
                    'phone_mobile_only'     => $phone_mobile_only,
                    'compliance_events'     => $compliance_events,
                    'name_industry_mismatch'=> $name_industry_mismatch,
                    'ssl_enabled'           => $ssl_enabled,
                    'domain_suspicious'     => $domain_suspicious,
                ],
                'overall' => [
                    'score' => $score100,
                    'grade' => $grade,
                ],
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
                        $icon = $this->state_icon($cls);
                        ?>
                        <div class="dx-card <?php echo esc_attr($cls); ?>">
                            <div class="dx-head">
                                <span class="dx-title"><?php echo esc_html($icon.' '.$title); ?></span>
                                <span class="dx-badge"><?php echo esc_html($pct); ?>％</span>
                            </div>
                            <div class="dx-bar <?php echo $isPenalty ? 'invert' : ''; ?>"><span style="width: <?php echo esc_attr($pct); ?>%"></span></div>
                            <div class="dx-text"><?php echo wp_kses_post($this->diagnostic_text($sectionKey, $pct, $sc)); ?></div>
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
                        <div class="dx-text"><?php echo wp_kses_post($this->diagnostic_text('overall', $sc['pct']['overall'], $sc)); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        $inline_js = "(function(){
            function boot(){
                if (typeof Chart === 'undefined'){ setTimeout(boot, 50); return; }
                var ctx = document.getElementById('" . esc_js($uid) . "').getContext('2d');
                var data = {
                    labels: " . wp_json_encode($sc['labels']) . ",
                    datasets: [{ label: 'CDB Score', data: " . wp_json_encode($sc['dataset']) . ", fill: true, pointRadius: 3, tension: 0.2 }]
                };
                new Chart(ctx, { type: 'radar', data: data, options: { responsive: true, plugins: { legend: { display: false } }, scales: { r: { beginAtZero: true, angleLines: { display: true }, grid: { display: true } } } } });
            }
            if (document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
        })();";
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
.dx-text .dx-paragraph{margin-bottom:8px}
.dx-text .dx-list{margin:0 0 6px 1.1em;padding:0}
.dx-text .dx-list li{margin:2px 0}
.dx-text .dx-tips{margin-top:6px;background:#fff7ed;border:1px solid #fed7aa;color:#7c2d12;padding:8px 10px;border-radius:8px;font-size:12.5px}
.dx-card.good{border-color:#c7f9e5; box-shadow:0 4px 16px rgba(16,185,129,.08)}
.dx-card.ok{border-color:#dbeafe}
.dx-card.warn{border-color:#fde68a}
.dx-card.bad{border-color:#fecaca}
.dx-card.critical{border-color:#fca5a5; box-shadow:0 4px 16px rgba(239,68,68,.10)}
@media (max-width:480px){.zdb-score{padding:14px;border-radius:14px}.dx-title{font-size:12px}.dx-text{font-size:12.5px}}
</style>';
});

/**
 * ===============================
 * ランキング特設ページ（REST + 検索/絞込/ソート/ページング/CSV/モーダル）
 * 追加改修点（このコミット）：
 *  - アイキャッチ（投稿サムネイル）を企業名の左に丸型35pxで表示
 *  - 長い社名でもアイコンとスコアバッジが潰れないように行高・折返し調整
 *  - 初期表示で「総合スコア（高→低）」が正しく効くように、REST側を「全件取得→PHP内でスコア計算→ソート→手動ページネーション」に変更
 *  - クリック委譲の重複バインド防止
 * ===============================
 */

// REST ルート登録
add_action('rest_api_init', function(){
    register_rest_route('cdb/v1', '/companies', [
        'methods'  => 'GET',
        'callback' => function(WP_REST_Request $req){
            $obj = $GLOBALS['cdb_company_score_instance'] ?? null;
            if (!$obj || !($obj instanceof CDB_Company_Score_Plugin)) $obj = new CDB_Company_Score_Plugin();
            $s = (new ReflectionClass($obj))->getMethod('get_settings');
            $s->setAccessible(true); $settings = $s->invoke($obj);

            $page     = max(1, intval($req->get_param('page') ?: 1));
            $per_page = max(1, min(200, intval($req->get_param('per_page') ?: 24)));
            $post_type= sanitize_key($req->get_param('post_type') ?: $settings['post_type']);
            $q        = sanitize_text_field($req->get_param('q') ?: '');
            $sort     = sanitize_text_field($req->get_param('sort') ?: 'score_desc');
            $corp     = sanitize_text_field($req->get_param('corp') ?: '');
            $addr     = sanitize_text_field($req->get_param('addr') ?: '');
            $flag     = sanitize_text_field($req->get_param('flag') ?: '');
            $min      = max(0, min(100, intval($req->get_param('min') ?: 0)));
            $max      = max(0, min(100, intval($req->get_param('max') ?: 100)));

            // ベース WP_Query（全件取得してから手動ページネーション）
            $meta_query = ['relation' => 'AND'];
            if ($corp !== '') $meta_query[] = ['key'=>$settings['metakeys']['corp_class'],'value'=>$corp,'compare'=>'='];
            if ($addr !== '') $meta_query[] = ['key'=>$settings['metakeys']['address_type'],'value'=>$addr,'compare'=>'='];
            if ($flag === 'ssl')    $meta_query[] = ['key'=>$settings['metakeys']['ssl_enabled'],'value'=>'1','compare'=>'='];
            if ($flag === 'fixed')  $meta_query[] = ['key'=>$settings['metakeys']['phone_has_fixed'],'value'=>'1','compare'=>'='];
            if ($flag === 'pr')     $meta_query[] = ['key'=>$settings['metakeys']['pr_last12m'],'value'=>0,'compare'=>'>'];
            if ($flag === 'grants') $meta_query[] = ['key'=>$settings['metakeys']['grants_awarded'],'value'=>0,'compare'=>'>'];

            $args = [
                'post_type'      => $post_type,
                'post_status'    => 'publish',
                'posts_per_page' => -1,             // ★ 全件取得
                's'              => $q,
                'meta_query'     => $meta_query,
                'no_found_rows'  => true,
                'fields'         => 'ids',
            ];
            $query = new WP_Query($args);
            $post_ids = $query->posts ?: [];

            $items_all = [];
            foreach ($post_ids as $pid){
                // スコア計算
                $calc = (new ReflectionClass($obj))->getMethod('calc_scores');
                $calc->setAccessible(true); $sc = $calc->invoke($obj, $pid);
                if ($sc['score'] < $min || $sc['score'] > $max) continue;
                $m = $settings['metakeys'];
                $domain = get_post_meta($pid, $m['domain'], true);
                $rep    = get_post_meta($pid, $m['representative_name'], true);
                $pr     = intval(get_post_meta($pid, $m['pr_last12m'], true));
                $gr     = intval(get_post_meta($pid, $m['grants_awarded'], true));
                $addr_t = get_post_meta($pid, $m['address_type'], true);
                $ssl    = get_post_meta($pid, $m['ssl_enabled'], true) ? true:false;
                $fixed  = get_post_meta($pid, $m['phone_has_fixed'], true) ? true:false;
                $addr_label = $addr_t==='real'?'実在':($addr_t==='rental'?'レンタル':($addr_t==='virtual'?'バーチャル':'未入力'));
                $thumb  = get_the_post_thumbnail_url($pid, 'thumbnail'); // ★ アイキャッチ

                $badges = [
                    'verified'       => ($sc['pct']['basic']>=80 && $ssl),
                    'strong_digital' => ($sc['pct']['digital']>=70),
                    'gov_grant'      => ($gr>0),
                ];

                $items_all[] = [
                    'id'     => $pid,
                    'name'   => get_the_title($pid),
                    'link'   => get_permalink($pid),
                    'modified' => intval(get_post_modified_time('U', true, $pid)),
                    'domain' => $domain,
                    'rep'    => $rep,
                    'basic'  => $sc['pct']['basic'],
                    'digital'=> $sc['pct']['digital'],
                    'credit' => $sc['pct']['credit'],
                    'pen'    => $sc['pct']['penalty'],
                    'score'  => $sc['score'],
                    'grade'  => $sc['grade'],
                    'corp'   => get_post_meta($pid, $m['corp_class'], true),
                    'addr'   => $addr_t,
                    'addr_label'=> $addr_label,
                    'ssl'    => $ssl,
                    'fixed'  => $fixed,
                    'pr'     => $pr,
                    'grants' => $gr,
                    'badges' => $badges,
                    'thumb'  => $thumb ?: '',
                ];
            }

            // ★ ソート（全件に対して適用）
            $cmp = function($a,$b) use($sort){
                switch ($sort) {
                    case 'credit_desc':  return $b['credit'] <=> $a['credit'];
                    case 'digital_desc': return $b['digital'] <=> $a['digital'];
                    case 'basic_desc':   return $b['basic']  <=> $a['basic'];
                    case 'name_asc':     return strnatcasecmp($a['name'], $b['name']);
                    case 'updated_desc': return ($b['modified'] ?? 0) <=> ($a['modified'] ?? 0);
                    case 'score_desc':
                    default:
                        return $b['score'] <=> $a['score'];
                }
            };
            usort($items_all, $cmp);

            // ★ 手動ページネーション
            $total = count($items_all);
            $pages = max(1, intval(ceil($total / $per_page)));
            $offset = ($page - 1) * $per_page;
            $items = array_slice($items_all, $offset, $per_page);

            return new WP_REST_Response([
                'page'  => $page,
                'pages' => $pages,
                'total' => $total,   // フィルタ・スコア範囲後の総数
                'items' => array_values($items),
            ], 200);
        },
        'permission_callback' => '__return_true'
    ]);
});

// 固定ページ用ショートコード
add_shortcode('cdb_company_ranking_rest', function($atts){
    $obj = $GLOBALS['cdb_company_score_instance'] ?? null;
    if (!$obj || !($obj instanceof CDB_Company_Score_Plugin)) $obj = new CDB_Company_Score_Plugin();
    $s = (new ReflectionClass($obj))->getMethod('get_settings');
    $s->setAccessible(true); $settings = $s->invoke($obj);

    $atts = shortcode_atts([
        'title'     => '企業の信用ランキング',
        'desc'      => '並べ替えと絞り込みで、気になる企業を見つけましょう。',
        'per_page'  => 24,
        'post_type' => $settings['post_type'],
    ], $atts, 'cdb_company_ranking_rest');

    $rest  = esc_url_raw( rest_url('cdb/v1/companies') );
    $nonce = wp_create_nonce('wp_rest');
    wp_enqueue_script('chartjs');

    ob_start(); ?>
    <style>
      .cdb-r-wrap{--bd:#e5e7eb;--pill:#f1f5f9;--mut:#64748b;--brand:#0f172a}
      .cdb-r-hero{margin:8px 0 12px;padding:14px;border:1px solid var(--bd);border-radius:16px;background:#fff}
      .cdb-r-hero h2{margin:0 0 8px;font-size:24px;color:#0f172a}
      .cdb-hero-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:6px 0}
      .cdb-pill{background:var(--pill);border:1px solid var(--bd);border-radius:999px;padding:6px 10px;font-size:12.5px;white-space:nowrap}
      .cdb-stats{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
      .cdb-stat{background:#eef2ff;border:1px solid #e0e7ff;color:#3730a3;border-radius:10px;padding:6px 10px;font-size:12.5px}

      .cdb-r-controls{position:sticky;top:0;z-index:2;background:#fff;padding:10px;border:1px solid var(--bd);border-radius:12px;margin:0 0 12px;display:grid;grid-template-columns:1.2fr 1fr 1fr 1fr 1fr 1fr;gap:8px}
      .cdb-r-controls input,.cdb-r-controls select, .cdb-r-controls button{width:100%;padding:8px;border:1px solid var(--bd);border-radius:10px;font-size:13px;background:#fff}

      
      /* --- Loading (brand blue/black) --- */
      .cdb-r-wrap{--cdb-blue:#0ea5e9;--brand-black:#0a0a0a}
      .cdb-loader{display:none;border:1px solid var(--bd);border-radius:14px;padding:18px;background:#fff;min-height:90px;display:flex;align-items:center;justify-content:center;gap:12px;color:var(--brand-black);}
      .cdb-spinner{width:28px;height:28px;border-radius:50%;border:3px solid #e5e7eb;border-top-color:var(--cdb-blue);animation:cdbspin .9s linear infinite}
      @keyframes cdbspin{to{transform:rotate(360deg)}}
      .cdb-loader-text{font-size:14px;letter-spacing:.2px}
    
      .cdb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px}
      .cdb-card{border:1px solid var(--bd);border-radius:14px;padding:14px;background:#fff;box-shadow:0 6px 18px rgba(2,6,23,.06)}
      /* ★ ヘッダー部を3カラム（アイコン+名前/ドメイン | 余白伸縮 | スコア） */
      .cdb-card .top{display:grid;grid-template-columns:auto 1fr auto;gap:12px;align-items:center}
      .cdb-head{display:flex;align-items:center;gap:10px;min-width:0}
      .cdb-avatar{width:35px;height:35px;border-radius:999px;object-fit:cover;flex:0 0 35px;background:#f1f5f9;border:1px solid #e5e7eb}
      .cdb-avatar-fallback{width:35px;height:35px;border-radius:999px;display:flex;align-items:center;justify-content:center;background:#e2e8f0;color:#334155;font-weight:800;border:1px solid #cbd5e1}
      .cdb-name-block{min-width:0}
      .cdb-name{font-weight:800;color:#0f172a;line-height:1.25;word-break:break-word}
      .cdb-domain{font-size:12px;color:#64748b;line-height:1.2;word-break:break-all}
      .cdb-score-badge{font-weight:800;border-radius:8px;padding:4px 8px;border:1px solid var(--bd);background:#f8fafc;white-space:nowrap}

      .cdb-bars{display:grid;gap:8px;margin-top:20px}
      .cdb-bar{height:20px;background:#f1f5f9;border-radius:999px;overflow:hidden;cursor:pointer;position:relative}
      .cdb-bar span{display:block;height:100%;width:0;background:linear-gradient(90deg,#22c55e,#06b6d4);border-radius:999px}
      .cdb-bar .lbl{position:absolute;left:8px;top:32%;transform:translateY(-50%);font-size:11px;color:#334155;pointer-events:none;white-space:nowrap}
      .cdb-bar-pen{height:20px;background:#fff1f2;border:1px solid #fecaca;border-radius:999px;overflow:hidden;cursor:pointer;position:relative}
      .cdb-bar-pen span{display:block;height:100%;width:0;background:linear-gradient(90deg,#f97316,#ef4444,#dc2626)}
      .cdb-bar-pen .lbl{position:absolute;left:8px;top:32%;transform:translateY(-50%);font-size:11px;color:#000000;pointer-events:none;white-space:nowrap}

      .cdb-tags{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
      .cdb-tag{font-size:11.5px;background:#eef2ff;border:1px solid #e0e7ff;color:#3730a3;border-radius:999px;padding:4px 8px}
      .cdb-card .actions{margin-top:12px;display:flex;justify-content:flex-end}
      .cdb-btn{display:inline-block;padding:8px 12px;border:1px solid var(--bd);border-radius:10px;background:#fff;font-size:13px;text-decoration:none}
      .cdb-btn:hover{background:#f8fafc}
      .cdb-pager{display:flex;gap:8px;justify-content:center;margin:14px 0}
      .cdb-pager button{padding:8px 12px;border:1px solid var(--bd);border-radius:10px;background:#fff}
      .cdb-empty{text-align:center;color:#64748b;margin:16px 0}

      .cdb-modal{position:fixed;inset:0;background:rgba(15,23,42,.45);display:none;align-items:center;justify-content:center;padding:16px;z-index:10000}
      .cdb-modal .box{background:#fff;border-radius:16px;border:1px solid #e5e7eb;max-width:620px;width:100%;padding:16px;box-shadow:0 20px 60px rgba(0,0,0,.25)}
      .cdb-modal .box h3{margin:0 0 10px}
      .cdb-modal .head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
      .cdb-close{border:1px solid var(--bd);background:#fff;border-radius:10px;padding:6px 10px;cursor:pointer}

      @media (max-width:960px){.cdb-r-controls{grid-template-columns:1fr 1fr 1fr}}
    </style>

    <div class="cdb-r-wrap" data-endpoint="<?php echo esc_url($rest); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" data-pt="<?php echo esc_attr($atts['post_type']); ?>">
      <div class="cdb-r-hero">
        <div class="cdb-hero-row"><h2><?php echo esc_html($atts['title']); ?></h2></div>
        <div class="cdb-hero-row cdb-stats">
          <div class="cdb-stat">対象社数：<span id="statTotal">--</span></div>
          <div class="cdb-stat">A以上：<span id="statAplus">--</span></div>
        </div>
        <div class="cdb-hero-row"><span class="cdb-pill"><?php echo esc_html($atts['desc']); ?></span><span class="cdb-pill">※各バー（基本/デジタル/信用/減点）をクリックで詳細チャート</span></div>
      </div>

      <div class="cdb-r-controls" id="cdbRCtl">
        <input type="search" id="rq" placeholder="社名・代表者・ドメインで検索">
        <select id="rsort">
          <option value="score_desc" selected>並び替え：総合スコア（高→低）</option>
          <option value="credit_desc">信用・規模（高→低）</option>
          <option value="digital_desc">デジタル発信（高→低）</option>
          <option value="basic_desc">基本透明性（高→低）</option>
          <option value="updated_desc">更新日（新→旧）</option>
          <option value="name_asc">社名（50音順）</option>
        </select>
        <select id="rcorp">
          <option value="">法人区分：すべて</option>
          <option value="listed">上場</option><option value="large">大企業</option>
          <option value="mid">中堅</option><option value="sme">中小</option><option value="sole">個人事業</option>
        </select>
        <select id="raddr">
          <option value="">住所タイプ：すべて</option>
          <option value="real">実在</option><option value="rental">レンタル</option><option value="virtual">バーチャル</option>
        </select>
        <select id="rflag">
          <option value="">追加条件：なし</option>
          <option value="ssl">SSLあり</option>
          <option value="fixed">固定電話あり</option>
          <option value="pr">PRあり（直近12ヶ月）</option>
          <option value="grants">採択歴あり</option>
        </select>
        <div style="display:flex;gap:6px">
          <input type="number" id="rmin" min="0" max="100" value="0" placeholder="最小スコア">
          <input type="number" id="rmax" min="0" max="100" value="100" placeholder="最大スコア">
        </div>
        <button id="rexport" style="display: none;">CSVエクスポート</button>
      </div>

      <div class="cdb-loader" id="rloader" data-perpage="<?php echo (int)$atts['per_page']; ?>" style="display:none">
        <div class="cdb-spinner" aria-hidden="true"></div>
        <div class="cdb-loader-text"><strong>読み込み中…（<span id="rloaderTextCount"><?php echo (int)$atts['per_page']; ?></span>件）</strong></div>
      </div>
      
      <div class="cdb-grid" id="rgrid"></div>
      <div class="cdb-empty" id="rempty" style="display:none">条件に合う企業が見つかりませんでした。</div>
      <div class="cdb-pager" id="rpager"></div>
    </div>

    <div class="cdb-modal" id="cdbModal">
      <div class="box">
        <div class="head">
          <h3 id="mTitle">詳細</h3>
          <button class="cdb-close" id="mClose">閉じる</button>
        </div>
        <canvas id="mChart" width="520" height="320"></canvas>
        <div id="mMeta" style="margin-top:12px;color:#475569;font-size:13px"></div>
      </div>
    </div>

    <script>
    (function(){
      const wrap  = document.querySelector('.cdb-r-wrap');
      const EP    = wrap.dataset.endpoint;
      const NONCE = wrap.dataset.nonce;
      const PT    = wrap.dataset.pt;
      const perPage = <?php echo (int)$atts['per_page']; ?>;

      // UI
      const q = document.getElementById('rq');
      const sort = document.getElementById('rsort');
      const corp = document.getElementById('rcorp');
      const addr = document.getElementById('raddr');
      const flag = document.getElementById('rflag');
      const min  = document.getElementById('rmin');
      const max  = document.getElementById('rmax');
      const loader = document.getElementById('rloader');
      const loaderCount = document.getElementById('rloaderTextCount');
      const grid = document.getElementById('rgrid');
      const pager= document.getElementById('rpager');
      const empty= document.getElementById('rempty');
      const expBtn = document.getElementById('rexport');
      const statTotal = document.getElementById('statTotal');
      const statAplus = document.getElementById('statAplus');
function scrollToRankingTop(){
  try{
    const wrap = document.querySelector('.cdb-r-wrap');
    if (wrap && wrap.scrollIntoView){
      wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  }catch(e){/* noop */}
}

      // Modal (smaller)
      const modal = document.getElementById('cdbModal');
      const mTitle= document.getElementById('mTitle');
      const mMeta = document.getElementById('mMeta');
      const mClose= document.getElementById('mClose');
      let mChart;

      let state = { page:1, pages:1, total:0, items:[] };

      function showLoader(txt){
        if(!loader) return; loader.style.display='flex';
        if(loaderCount && typeof txt==='string') loaderCount.textContent = txt.replace(/[^0-9]/g,'') || loaderCount.textContent;
      }
      function hideLoader(){ if(loader) loader.style.display='none'; }

      function params(page=1){
        const u = new URL(EP);
        u.searchParams.set('page', page);
        u.searchParams.set('per_page', perPage);
        u.searchParams.set('post_type', PT);
        // ★ 初期状態でもscore_descが必ず付与されるように
        u.searchParams.set('sort', sort.value || 'score_desc');
        if (q.value) u.searchParams.set('q', q.value);
        if (corp.value) u.searchParams.set('corp', corp.value);
        if (addr.value) u.searchParams.set('addr', addr.value);
        if (flag.value) u.searchParams.set('flag', flag.value);
        const vmin = parseInt(min.value||'0'), vmax = parseInt(max.value||'100');
        u.searchParams.set('min', Math.max(0, Math.min(100, vmin)));
        u.searchParams.set('max', Math.max(0, Math.min(100, vmax)));
        return u.toString();
      }

      async function fetchPage(page=1){
        scrollToRankingTop();   // ← この1行を追加
        grid.innerHTML = '';
        pager.innerHTML= '';
        empty.style.display='none';
        showLoader(String(perPage));
        try{
          const res = await fetch(params(page), { headers: { 'X-WP-Nonce': NONCE } });
          const json = await res.json();
          state = json;
          render();
          hideLoader();
          statTotal.textContent = state.total || 0;
          computeAPlus();
        }catch(e){ hideLoader();
          console.error(e);
          empty.style.display='block';
          empty.textContent = '読み込みでエラーが発生しました。';
        }
      }

      async function computeAPlus(){
        try{
          let count = 0;
          const u1 = new URL(params(1));
          u1.searchParams.set('per_page', 200);
          const r1 = await fetch(u1, { headers: { 'X-WP-Nonce': NONCE } });
          const j1 = await r1.json();
          count += (j1.items||[]).filter(it=> (it.grade==='A' || it.grade==='A+')).length;
          const pages = j1.pages || 1;
          for (let p=2; p<=pages; p++){
            const up = new URL(params(p));
            up.searchParams.set('per_page', 200);
            const rp = await fetch(up, { headers: { 'X-WP-Nonce': NONCE } });
            const jp = await rp.json();
            count += (jp.items||[]).filter(it=> (it.grade==='A' || it.grade==='A+')).length;
          }
          statAplus.textContent = count;
        }catch(e){ hideLoader(); statAplus.textContent = '—'; }
      }

      function badgeEls(b){
        const out=[];
        if (b.verified) out.push('<span class="cdb-tag">✅ Verified</span>');
        if (b.strong_digital) out.push('<span class="cdb-tag">📣 Strong Digital</span>');
        if (b.gov_grant) out.push('<span class="cdb-tag">🏛 Gov Grant</span>');
        return out.join('');
      }
      function escapeHtml(s){ return (s||'').replace(/[&<>\"]/g, c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;' }[c])); }

      // ★ アバター（35x35）テンプレート
      function avatarTemplate(it){
        if (it.thumb){
          return `<img class="cdb-avatar" src="${escapeHtml(it.thumb)}" alt="">`;
        } else {
          const init = (it.name||'')[0] || '?';
          return `<div class="cdb-avatar-fallback">${escapeHtml(init)}</div>`;
        }
      }

      function cardTemplate(it){
        return `
        <div class="cdb-card" data-id="${it.id}" data-name="${escapeHtml(it.name)}" data-basic="${it.basic}" data-digital="${it.digital}" data-credit="${it.credit}" data-pen="${it.pen}" data-score="${it.score}">
          <div class="top">
            <div class="cdb-head">
              ${avatarTemplate(it)}
              <div class="cdb-name-block">
                <div class="cdb-name">${escapeHtml(it.name)}</div>
                <div class="cdb-domain">${escapeHtml(it.domain || 'ドメイン未登録')} ・ 代表：${escapeHtml(it.rep || '―')}</div>
              </div>
            </div>
            <div></div>
            <div><span class="cdb-score-badge">${it.score}/100（${escapeHtml(it.grade)}）</span></div>
          </div>
          <div class="cdb-bars" title="クリックで詳細チャート">
            <div class="cdb-bar" data-kind="basic"><span style="width:${it.basic}%"></span><span class="lbl">基本透明性</span></div>
            <div class="cdb-bar" data-kind="digital"><span style="width:${it.digital}%"></span><span class="lbl">デジタル発信</span></div>
            <div class="cdb-bar" data-kind="credit"><span style="width:${it.credit}%"></span><span class="lbl">信用・規模</span></div>
            <div class="cdb-bar-pen" data-kind="pen"><span style="width:${it.pen}%"></span><span class="lbl">減点（大きいほどリスク）</span></div>
          </div>
          <div class="cdb-tags">
            ${badgeEls(it.badges)}
            ${it.ssl ? '<span class="cdb-tag">SSL</span>' : ''}
            ${it.fixed ? '<span class="cdb-tag">固定電話</span>' : ''}
            ${it.addr ? '<span class="cdb-tag">住所:'+escapeHtml(it.addr_label)+'</span>' : ''}
            ${it.pr>0 ? '<span class="cdb-tag">PR:'+it.pr+'本</span>' : ''}
            ${it.grants>0 ? '<span class="cdb-tag">採択:'+it.grants+'回</span>' : ''}
          </div>
          <div class="actions">
            <a class="cdb-btn" href="${it.link}" target="_blank" rel="noopener">詳細を見る</a>
          </div>
        </div>`;
      }

      function render(){
        const items = state.items || [];
        if (!items.length){ empty.style.display='block'; grid.innerHTML=''; pager.innerHTML=''; return; }
        empty.style.display='none';
        showLoader(String(perPage));
        grid.innerHTML = items.map(cardTemplate).join('');
        // animate bars
        grid.querySelectorAll('.cdb-bar span, .cdb-bar-pen span').forEach(el=>{
          const w=el.style.width; el.style.width='0%'; setTimeout(()=>{ el.style.width=w; }, 0);
        });

        // ★ クリック委譲を重複バインドしない
        if (!grid.dataset.bound){
          grid.addEventListener('click', function(ev){
            const bar = ev.target.closest('.cdb-bar, .cdb-bar-pen');
            if (!bar) return;
            const card = ev.target.closest('.cdb-card');
            if (!card) return;
            openModal({
              id: card.dataset.id,
              name: card.dataset.name,
              basic: parseFloat(card.dataset.basic),
              digital: parseFloat(card.dataset.digital),
              credit: parseFloat(card.dataset.credit),
              pen: parseFloat(card.dataset.pen),
              score: parseFloat(card.dataset.score)
            });
          });
          grid.dataset.bound = '1';
        }

        // pager
        const pages = state.pages || 1;
        const page  = state.page  || 1;
        let html = '';
        if (pages>1){
          html += `<button data-p="${Math.max(1,page-1)}">前へ</button>`;
          const maxBtn = 7; let start=Math.max(1, page-3), end=Math.min(pages, start+maxBtn-1);
          if (end-start<maxBtn-1) start=Math.max(1, end-maxBtn+1);
          for (let i=start;i<=end;i++) html += `<button data-p="${i}" ${i===page?'style=\"font-weight:800\"':''}>${i}</button>`;
          html += `<button data-p="${Math.min(pages,page+1)}">次へ</button>`;
        }
        pager.innerHTML = html;
        pager.querySelectorAll('button[data-p]').forEach(btn=> btn.addEventListener('click', ()=> fetchPage(parseInt(btn.dataset.p,10))));
      }

      function openModal(it){
        mTitle.textContent = it.name + ' のミニチャート';
        mMeta.innerHTML = `
          <div>総合：<strong>${it.score}</strong>／100</div>
          <div>基本：${it.basic}% ・ デジタル：${it.digital}% ・ 信用：${it.credit}% ・ 減点：${it.pen}%</div>
        `;
        if (mChart){ try{ mChart.destroy(); }catch(e){ hideLoader();} }
        const ctx = document.getElementById('mChart').getContext('2d');
        mChart = new Chart(ctx, {
          type:'radar',
          data:{
            labels:['基本透明性','デジタル発信','信用・規模','総合スコア'],
            datasets:[{
              label:'CDB Score',
              data:[it.basic, it.digital, it.credit, it.score],
              fill:true,
              backgroundColor:'rgba(59,130,246,0.15)',
              borderColor:'rgba(59,130,246,0.9)',
              pointBackgroundColor:'rgba(59,130,246,1)',
              pointRadius:2.5,
              tension:0.2
            }]
          },
          options:{responsive:true, plugins:{legend:{display:false}}, scales:{r:{min:0,max:100,beginAtZero:true}}}
        });
        modal.style.display='flex';
      }
      mClose.addEventListener('click', ()=> modal.style.display='none');
      modal.addEventListener('click', (e)=> { if (e.target === modal) modal.style.display='none'; });

      // CSV Export（UTF-8 BOM）
      expBtn.addEventListener('click', async ()=>{
        expBtn.disabled = true; expBtn.textContent = '作成中...';
        try{
          const u1 = new URL(params(1)); u1.searchParams.set('per_page', 200);
          const res1 = await fetch(u1, { headers: { 'X-WP-Nonce': NONCE } }); const j1 = await res1.json();
          const pages = j1.pages || 1; let all = j1.items || [];
          for (let p=2; p<=pages; p++){
            const up = new URL(params(p)); up.searchParams.set('per_page', 200);
            const rsp = await fetch(up, { headers: { 'X-WP-Nonce': NONCE } }); const jp  = await rsp.json();
            all = all.concat(jp.items||[]);
          }
          const header = ['id','name','score','grade','basic','digital','credit','penalty','corp','addr','ssl','fixed','pr','grants','rep','domain','link'];
          const rows = [header.join(',')];
          const esc = (v)=>('"'+String(v??'').replace(/"/g,'""')+'"');
          all.forEach(it=>{
            rows.push([it.id, esc(it.name), it.score, it.grade, it.basic, it.digital, it.credit, it.pen,
              it.corp, it.addr, it.ssl?1:0, it.fixed?1:0, it.pr, it.grants, esc(it.rep||''), esc(it.domain||''), esc(it.link)
            ].join(','));
          });
          const csvText = '\uFEFF' + rows.join('\n');
          const blob = new Blob([csvText], {type:'text/csv;charset=utf-8;'});
          const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'cdb_ranking_export.csv'; document.body.appendChild(a); a.click(); a.remove();
        }catch(e){ hideLoader(); console.error(e); alert('CSV作成でエラーが発生しました。'); }
        finally{ expBtn.disabled = false; expBtn.textContent = 'CSVエクスポート'; }
      });

      ['input','change'].forEach(ev=>{
        q.addEventListener(ev, ()=> fetchPage(1));
        sort.addEventListener(ev, ()=> fetchPage(1));
        corp.addEventListener(ev, ()=> fetchPage(1));
        addr.addEventListener(ev, ()=> fetchPage(1));
        flag.addEventListener(ev, ()=> fetchPage(1));
        min.addEventListener(ev, ()=> fetchPage(1));
        max.addEventListener(ev, ()=> fetchPage(1));
      });

      // ★ 初期ロード（score_descが確実に適用される）
      fetchPage(1);
    })();
    </script>

    <?php
    return ob_get_clean();
});
