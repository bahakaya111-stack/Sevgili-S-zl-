<?php
/**
 * Plugin Name: BES Temsilciler (Şube/İl/İlçe/İşyeri Filtre)
 * Description: Temsilci yönetimi + modern filtre + hızlı temsilci ekleme + CSV/Excel içe aktarma (Şube → İl → İlçe → İşyeri).
 * Version: 1.7.8
 * Author: BahaLabs
 * Text Domain: bes-temsilciler
 */
if (!defined('ABSPATH')) exit;

class BES_Temsilciler_Plugin {
  const CPT = 'bes_temsilci';
  const TAX = 'bes_birim';
  const TAX_WORK = 'bes_isyeri_agaci';
  const TAX_ROLE = 'bes_gorev';
  const TAX_UNVAN = 'bes_unvan';
  const NONCE_ACTION = 'bes_temsilciler_nonce';
  const PROVINCE_TRANSIENT = 'bes_province_counts';

  public function __construct() {
    add_action('init', [$this, 'register_cpt_and_tax']);
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_assets']);
    add_action('add_meta_boxes', [$this, 'register_meta_boxes']);
    add_action('save_post', [$this, 'save_meta'], 10, 2);
    add_action('admin_head', [$this, 'remove_default_tax_metabox']);

    add_action('admin_post_bes_quick_add', [$this, 'handle_quick_add']);
    add_action('admin_post_bes_import', [$this, 'handle_import']);

    add_shortcode('bes_temsilciler', [$this, 'shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'frontend_enqueue_assets']);

    add_action('wp_ajax_bes_get_children', [$this, 'ajax_get_children']);
    add_action('wp_ajax_bes_get_work_children', [$this, 'ajax_get_work_children']);
    add_action('wp_ajax_bes_add_work_term', [$this, 'ajax_add_work_term']);
    add_action('wp_ajax_bes_add_role_term', [$this, 'ajax_add_role_term']);
    add_action('wp_ajax_bes_add_unvan_term', [$this, 'ajax_add_unvan_term']);
    add_action('wp_ajax_nopriv_bes_get_children', [$this, 'ajax_get_children']);
    add_action('wp_ajax_bes_get_temsilciler', [$this, 'ajax_get_temsilciler']);
    add_action('wp_ajax_nopriv_bes_get_temsilciler', [$this, 'ajax_get_temsilciler']);
    add_action('wp_ajax_bes_create_term', [$this, 'ajax_create_term']);

    add_filter('manage_edit-'.self::TAX.'_columns', [$this, 'tax_columns']);
    add_filter('manage_'.self::TAX.'_custom_column', [$this, 'tax_column_content'], 10, 3);
  }

  public function register_cpt_and_tax() {
    register_post_type(self::CPT, [
      'labels' => [
        'name' => 'Temsilciler',
        'singular_name' => 'Temsilci',
        'add_new' => 'Yeni Ekle',
        'add_new_item' => 'Yeni Temsilci Ekle',
        'edit_item' => 'Temsilciyi Düzenle',
      ],
      'public' => true,
      'show_in_menu' => true,
      'menu_icon' => 'dashicons-id',
      'supports' => ['title','thumbnail'],
      'has_archive' => false,
      'rewrite' => ['slug' => 'temsilci'],
      'show_in_rest' => true,
    ]);

    // 1) Sendika Birim Ağacı: Şube -> İl -> İlçe -> (Sendika) İşyeri
    register_taxonomy(self::TAX, [self::CPT], [
      'labels' => [
        'name' => 'Birim Ağacı (Şube/İl/İlçe/İşyeri)',
        'singular_name' => 'Birim',
        'search_items' => 'Birim Ara',
        'all_items' => 'Tüm Birimler',
        'parent_item' => 'Üst Birim',
        'parent_item_colon' => 'Üst Birim:',
        'edit_item' => 'Birim Düzenle',
        'update_item' => 'Birim Güncelle',
        'add_new_item' => 'Yeni Birim Ekle',
        'new_item_name' => 'Yeni Birim Adı',
        'menu_name' => 'Birimler',
      ],
      'hierarchical' => true,
      'show_ui' => true,
      'show_admin_column' => true,
      'show_in_rest' => true,
      'rewrite' => ['slug' => 'birim'],
    ]);

    // 2) Kurum/İşyeri Hiyerarşisi: Bakanlık -> Kurum Merkezi -> Bölge/İl Md -> İşyeri Adı
    register_taxonomy(self::TAX_WORK, [self::CPT], [
      'labels' => [
        'name' => 'İşyeri Hiyerarşisi (Bakanlık/Kurum/Bölge/İşyeri)',
        'singular_name' => 'İşyeri',
        'search_items' => 'İşyeri Ara',
        'all_items' => 'Tüm İşyerleri',
        'parent_item' => 'Üst İşYeri',
        'parent_item_colon' => 'Üst İşYeri:',
        'edit_item' => 'İşyeri Düzenle',
        'update_item' => 'İşyeri Güncelle',
        'add_new_item' => 'Yeni İşyeri Ekle',
        'new_item_name' => 'Yeni İşyeri Adı',
        'menu_name' => 'İşyerleri',
      ],
      'hierarchical' => true,
      'show_ui' => true,
      'show_admin_column' => false,
      'show_in_rest' => true,
      'rewrite' => ['slug' => 'isyeri'],
    ]);

    // 3) Sendikadaki görevler (etiket gibi)
    register_taxonomy(self::TAX_ROLE, [self::CPT], [
      'labels' => [
        'name' => 'Sendika Görevleri',
        'singular_name' => 'Görev',
        'search_items' => 'Görev Ara',
        'all_items' => 'Tüm Görevler',
        'edit_item' => 'Görev Düzenle',
        'update_item' => 'Görev Güncelle',
        'add_new_item' => 'Yeni Görev Ekle',
        'new_item_name' => 'Yeni Görev',
        'menu_name' => 'Görevler',
      ],
      'hierarchical' => false,
      'show_ui' => true,
      'show_admin_column' => false,
      'show_in_rest' => true,
      'rewrite' => ['slug' => 'gorev'],
    ]);

    // 4) Ünvanlar (seçilebilir liste)
    register_taxonomy(self::TAX_UNVAN, [self::CPT], [
      'labels' => [
        'name' => 'Ünvanlar',
        'singular_name' => 'Ünvan',
        'search_items' => 'Ünvan Ara',
        'all_items' => 'Tüm Ünvanlar',
        'edit_item' => 'Ünvan Düzenle',
        'update_item' => 'Ünvan Güncelle',
        'add_new_item' => 'Yeni Ünvan Ekle',
        'new_item_name' => 'Yeni Ünvan',
        'menu_name' => 'Ünvanlar',
      ],
      'hierarchical' => false,
      'show_ui' => true,
      'show_admin_column' => false,
      'show_in_rest' => true,
      'rewrite' => ['slug' => 'unvan'],
    ]);
  }

  private function get_root_terms() {
    return get_terms(['taxonomy' => self::TAX,'hide_empty' => false,'parent' => 0,'orderby' => 'name','order' => 'ASC']);
  }

  private function term_depth($term_id) {
    $depth = 0; $term = get_term($term_id, self::TAX);
    if (!$term || is_wp_error($term)) return 0;
    while ($term->parent) { $depth++; $term = get_term($term->parent, self::TAX); if (!$term || is_wp_error($term) || $depth>10) break; }
    return $depth;
  }

  private function depth_label($depth) {
    switch ($depth) { case 0: return 'Şube'; case 1: return 'İl'; case 2: return 'İlçe'; case 3: return 'İşyeri'; default: return 'Birim'; }
  }

  private function build_whatsapp_link($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    if (strpos($raw,'http://')===0 || strpos($raw,'https://')===0) return $raw;
    $num = preg_replace('/\D+/', '', $raw);
    if ($num === '') return '';
    return 'https://wa.me/'.$num;
  }

  private function sanitize_term_name($name) {
    $name = wp_strip_all_tags((string)$name);
    $name = trim(preg_replace('/\s+/', ' ', $name));
    return $name;
  }

  private function validate_parent_depth_for_new_term($parent_id) {
    if ($parent_id<=0) return ['ok'=>true];
    if ($this->term_depth($parent_id) >= 3) return ['ok'=>false,'message'=>'İşyeri altına birim eklenemez.'];
    return ['ok'=>true];
  }

  private function ensure_term($name, $parent_id=0) {
    $name = $this->sanitize_term_name($name);
    if ($name==='') return 0;
    $found = get_terms(['taxonomy'=>self::TAX,'hide_empty'=>false,'name'=>$name,'parent'=>$parent_id,'number'=>1]);
    if (!is_wp_error($found) && !empty($found)) return (int)$found[0]->term_id;
    $v = $this->validate_parent_depth_for_new_term($parent_id); if(!$v['ok']) return 0;
    $res = wp_insert_term($name, self::TAX, ['parent'=>$parent_id]);
    if (is_wp_error($res)) return 0;
    return (int)$res['term_id'];
  }
  private function tr_province_code($name){
    $n = trim((string)$name);
    if($n==='') return '';
    $n = mb_strtoupper($n, 'UTF-8');
    // Normalize Turkish letters → ASCII
    $n = strtr($n, ['İ'=>'I','İ'=>'I','Ğ'=>'G','Ü'=>'U','Ş'=>'S','Ö'=>'O','Ç'=>'C','Â'=>'A','Î'=>'I','Û'=>'U']);
    $n = preg_replace('/\s+/u',' ', $n);

    $map = [
      'ADANA'=>'TR-01','ADIYAMAN'=>'TR-02','AFYONKARAHISAR'=>'TR-03','AGRI'=>'TR-04','AMASYA'=>'TR-05',
      'ANKARA'=>'TR-06','ANTALYA'=>'TR-07','ARTVIN'=>'TR-08','AYDIN'=>'TR-09','BALIKESIR'=>'TR-10',
      'BILECIK'=>'TR-11','BINGOL'=>'TR-12','BITLIS'=>'TR-13','BOLU'=>'TR-14','BURDUR'=>'TR-15',
      'BURSA'=>'TR-16','CANAKKALE'=>'TR-17','CANKIRI'=>'TR-18','CORUM'=>'TR-19','DENIZLI'=>'TR-20',
      'DIYARBAKIR'=>'TR-21','EDIRNE'=>'TR-22','ELAZIG'=>'TR-23','ERZINCAN'=>'TR-24','ERZURUM'=>'TR-25',
      'ESKISEHIR'=>'TR-26','GAZIANTEP'=>'TR-27','GIRESUN'=>'TR-28','GUMUSHANE'=>'TR-29','HAKKARI'=>'TR-30',
      'HATAY'=>'TR-31','ISPARTA'=>'TR-32','MERSIN'=>'TR-33','ISTANBUL'=>'TR-34','IZMIR'=>'TR-35',
      'KARS'=>'TR-36','KASTAMONU'=>'TR-37','KAYSERI'=>'TR-38','KIRKLARELI'=>'TR-39','KIRSEHIR'=>'TR-40',
      'KOCAELI'=>'TR-41','KONYA'=>'TR-42','KUTAHYA'=>'TR-43','MALATYA'=>'TR-44','MANISA'=>'TR-45',
      'KAHRAMANMARAS'=>'TR-46','MARDIN'=>'TR-47','MUGLA'=>'TR-48','MUS'=>'TR-49','NEVSEHIR'=>'TR-50',
      'NIGDE'=>'TR-51','ORDU'=>'TR-52','RIZE'=>'TR-53','SAKARYA'=>'TR-54','SAMSUN'=>'TR-55',
      'SIIRT'=>'TR-56','SINOP'=>'TR-57','SIVAS'=>'TR-58','TEKIRDAG'=>'TR-59','TOKAT'=>'TR-60',
      'TRABZON'=>'TR-61','TUNCELI'=>'TR-62','SANLIURFA'=>'TR-63','USAK'=>'TR-64','VAN'=>'TR-65',
      'YOZGAT'=>'TR-66','ZONGULDAK'=>'TR-67','AKSARAY'=>'TR-68','BAYBURT'=>'TR-69','KARAMAN'=>'TR-70',
      'KIRIKKALE'=>'TR-71','BATMAN'=>'TR-72','SIRNAK'=>'TR-73','BARTIN'=>'TR-74','ARDAHAN'=>'TR-75',
      'IGDIR'=>'TR-76','YALOVA'=>'TR-77','KARABUK'=>'TR-78','KILIS'=>'TR-79','OSMANIYE'=>'TR-80','DUZCE'=>'TR-81'
    ];
    return $map[$n] ?? '';
  }

  private function province_code_name_map(){
    return [
      'TR-01'=>'Adana','TR-02'=>'Adıyaman','TR-03'=>'Afyonkarahisar','TR-04'=>'Ağrı','TR-05'=>'Amasya',
      'TR-06'=>'Ankara','TR-07'=>'Antalya','TR-08'=>'Artvin','TR-09'=>'Aydın','TR-10'=>'Balıkesir',
      'TR-11'=>'Bilecik','TR-12'=>'Bingöl','TR-13'=>'Bitlis','TR-14'=>'Bolu','TR-15'=>'Burdur',
      'TR-16'=>'Bursa','TR-17'=>'Çanakkale','TR-18'=>'Çankırı','TR-19'=>'Çorum','TR-20'=>'Denizli',
      'TR-21'=>'Diyarbakır','TR-22'=>'Edirne','TR-23'=>'Elazığ','TR-24'=>'Erzincan','TR-25'=>'Erzurum',
      'TR-26'=>'Eskişehir','TR-27'=>'Gaziantep','TR-28'=>'Giresun','TR-29'=>'Gümüşhane','TR-30'=>'Hakkari',
      'TR-31'=>'Hatay','TR-32'=>'Isparta','TR-33'=>'Mersin','TR-34'=>'İstanbul','TR-35'=>'İzmir',
      'TR-36'=>'Kars','TR-37'=>'Kastamonu','TR-38'=>'Kayseri','TR-39'=>'Kırklareli','TR-40'=>'Kırşehir',
      'TR-41'=>'Kocaeli','TR-42'=>'Konya','TR-43'=>'Kütahya','TR-44'=>'Malatya','TR-45'=>'Manisa',
      'TR-46'=>'Kahramanmaraş','TR-47'=>'Mardin','TR-48'=>'Muğla','TR-49'=>'Muş','TR-50'=>'Nevşehir',
      'TR-51'=>'Niğde','TR-52'=>'Ordu','TR-53'=>'Rize','TR-54'=>'Sakarya','TR-55'=>'Samsun',
      'TR-56'=>'Siirt','TR-57'=>'Sinop','TR-58'=>'Sivas','TR-59'=>'Tekirdağ','TR-60'=>'Tokat',
      'TR-61'=>'Trabzon','TR-62'=>'Tunceli','TR-63'=>'Şanlıurfa','TR-64'=>'Uşak','TR-65'=>'Van',
      'TR-66'=>'Yozgat','TR-67'=>'Zonguldak','TR-68'=>'Aksaray','TR-69'=>'Bayburt','TR-70'=>'Karaman',
      'TR-71'=>'Kırıkkale','TR-72'=>'Batman','TR-73'=>'Şırnak','TR-74'=>'Bartın','TR-75'=>'Ardahan',
      'TR-76'=>'Iğdır','TR-77'=>'Yalova','TR-78'=>'Karabük','TR-79'=>'Kilis','TR-80'=>'Osmaniye','TR-81'=>'Düzce'
    ];
  }

  private function get_province_counts(){
    $cached = get_transient(self::PROVINCE_TRANSIENT);
    if(is_array($cached)) return $cached;

    $counts = [];
    $q = new WP_Query([
      'post_type'=>self::CPT,
      'post_status'=>'publish',
      'posts_per_page'=>-1,
      'fields'=>'ids',
      'no_found_rows'=>true,
    ]);
    foreach($q->posts as $pid){
      $terms = wp_get_object_terms($pid, self::TAX, ['fields'=>'all']);
      if(is_wp_error($terms) || empty($terms)) continue;
      $deepest = null;
      $deepest_depth = -1;
      foreach($terms as $term){
        $depth = $this->term_depth($term->term_id);
        if($depth > $deepest_depth){
          $deepest = $term;
          $deepest_depth = $depth;
        }
      }
      if(!$deepest) continue;
      $path = [$deepest->term_id];
      $p = $deepest->parent;
      while($p){
        $path[] = $p;
        $pt = get_term($p, self::TAX);
        if(!$pt || is_wp_error($pt)) break;
        $p = $pt->parent;
      }
      $path = array_reverse($path); // root->leaf
      if(count($path) < 2) continue; // no il
      $il_id = intval($path[1]);
      $il = get_term($il_id, self::TAX);
      if(!$il || is_wp_error($il)) continue;
      $code = $this->tr_province_code($il->name);
      if(!$code) continue;
      if(!isset($counts[$code])) $counts[$code]=0;
      $counts[$code] += 1;
    }
    wp_reset_postdata();
    $all = array_keys($this->province_code_name_map());
    $out = [];
    foreach($all as $code){
      $out[] = [$code, intval($counts[$code] ?? 0)];
    }
    set_transient(self::PROVINCE_TRANSIENT, $out, HOUR_IN_SECONDS);
    return $out;
  }

  private function delete_province_cache(){
    delete_transient(self::PROVINCE_TRANSIENT);
  }

  private function deepest_term_id($sube,$il,$ilce,$isyeri) {
    foreach ([$isyeri,$ilce,$il,$sube] as $x) { if ((int)$x>0) return (int)$x; }
    return 0;
  }

  private function get_level_label($post_id){
    $terms = wp_get_post_terms((int)$post_id, self::TAX, ['fields'=>'all']);
    if(is_wp_error($terms) || !$terms) return '';
    $bestDepth = -1;
    foreach($terms as $t){
      $depth = $this->term_depth($t->term_id);
      if($depth > $bestDepth) $bestDepth = $depth;
    }
    // depth: 0=Şube, 1=İl, 2=İlçe, 3=İşyeri
    if($bestDepth <= 0) return 'Şube';
    if($bestDepth == 1) return 'İl';
    if($bestDepth == 2) return 'İlçe';
    return 'İşyeri';
  }

  private function get_unvan_label($post_id){
    // Prefer taxonomy based unvan
    $terms = wp_get_post_terms((int)$post_id, self::TAX_UNVAN, ['fields'=>'names']);
    if(!is_wp_error($terms) && !empty($terms)){
      return implode(', ', array_slice($terms, 0, 3));
    }
    // Fallback to legacy meta
    $legacy = get_post_meta((int)$post_id, '_bes_unvan', true);
    return $legacy ? (string)$legacy : '';
  }

  private function sideload_image_to_post($url, $post_id) {
    $url = esc_url_raw((string)$url);
    if (!$url) return 0;
    if (!function_exists('media_sideload_image')) {
      require_once ABSPATH.'wp-admin/includes/media.php';
      require_once ABSPATH.'wp-admin/includes/file.php';
      require_once ABSPATH.'wp-admin/includes/image.php';
    }
    $tmp = media_sideload_image($url, $post_id, null, 'id');
    if (is_wp_error($tmp)) return 0;
    return (int)$tmp;
  }

  public function admin_menu() {
    add_submenu_page('edit.php?post_type='.self::CPT,'Hızlı Temsilci Ekle','Hızlı Ekle','edit_posts','bes-quick-add',[$this,'admin_page_quick_add']);
    add_submenu_page('edit.php?post_type='.self::CPT,'Toplu İçe Aktar','Toplu İçe Aktar','edit_posts','bes-import',[$this,'admin_page_import']);
    add_submenu_page('edit.php?post_type='.self::CPT,'Kolay Birim Ekle','Birim Ekle','manage_categories','bes-birim-ekle',[$this,'admin_page_birim']);

    // Yönetim sayfaları (WordPress'in standart taksonomi ekranı)
    add_submenu_page('edit.php?post_type='.self::CPT,'İşyeri Hiyerarşisi','İşyerleri','manage_categories','edit-tags.php?taxonomy='.self::TAX_WORK.'&post_type='.self::CPT);
    add_submenu_page('edit.php?post_type='.self::CPT,'Sendika Görevleri','Görevler','manage_categories','edit-tags.php?taxonomy='.self::TAX_ROLE.'&post_type='.self::CPT);
    add_submenu_page('edit.php?post_type='.self::CPT,'Ünvanlar','Ünvanlar','manage_categories','edit-tags.php?taxonomy='.self::TAX_UNVAN.'&post_type='.self::CPT);
  }

  public function admin_enqueue_assets($hook) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if(!$screen) return;
    $is_cpt = ($screen->post_type===self::CPT);
    $is_page = isset($_GET['page']) && in_array($_GET['page'], ['bes-birim-ekle','bes-quick-add','bes-import'], true);
    if(!$is_cpt && !$is_page) return;

    wp_register_style('bes-admin', plugins_url('assets/bes-admin.css', __FILE__), [], '1.7.8');
    wp_register_script('bes-admin', plugins_url('assets/bes-admin.js', __FILE__), ['jquery'], '1.7.8', true);
    wp_enqueue_style('bes-admin');
    wp_enqueue_script('bes-admin');

    if (isset($_GET['page']) && $_GET['page']==='bes-quick-add') wp_enqueue_media();

    wp_localize_script('bes-admin','BES_ADMIN',[
      'ajaxurl'=>admin_url('admin-ajax.php'),
      'nonce'=>wp_create_nonce(self::NONCE_ACTION),
      'mapsApiKey'=>get_option('bes_maps_api_key',''),
    ]);
  }

  public function remove_default_tax_metabox() { remove_meta_box(self::TAX.'div', self::CPT, 'side'); }

  public function admin_page_birim() {
    if(!current_user_can('manage_categories')) wp_die('Yetki yok.');
    ?>
    <div class="wrap bes-admin-wrap">
      <h1>Kolay Birim Ekleme (Şube → İl → İlçe → İşyeri)</h1>
      <p class="description">Birimleri hızlıca ekle ve hiyerarşiyi temiz tut.</p>

      <div class="bes-admin-card">
        <div class="bes-admin-grid">
          <div class="bes-admin-field">
            <label>Şube</label>
            <select id="bes-a0"><option value="">Seç / Yeni ekle</option></select>
            <div class="bes-admin-inline">
              <input type="text" id="bes-new0" placeholder="Yeni Şube adı">
              <button class="button button-primary" id="bes-add0">Ekle</button>
            </div>
          </div>

          <div class="bes-admin-field">
            <label>İl (opsiyonel)</label>
            <select id="bes-a1" disabled><option value="">Seç / Yeni ekle</option></select>
            <div class="bes-admin-inline">
              <input type="text" id="bes-new1" placeholder="Yeni İl adı">
              <button class="button" id="bes-add1" disabled>Ekle</button>
            </div>
          </div>

          <div class="bes-admin-field">
            <label>İlçe (opsiyonel)</label>
            <select id="bes-a2" disabled><option value="">Seç / Yeni ekle</option></select>
            <div class="bes-admin-inline">
              <input type="text" id="bes-new2" placeholder="Yeni İlçe adı">
              <button class="button" id="bes-add2" disabled>Ekle</button>
            </div>
          </div>

          <div class="bes-admin-field">
            <label>İşyeri (opsiyonel)</label>
            <select id="bes-a3" disabled><option value="">Seç / Yeni ekle</option></select>
            <div class="bes-admin-inline">
              <input type="text" id="bes-new3" placeholder="Yeni İşyeri adı">
              <button class="button" id="bes-add3" disabled>Ekle</button>
            </div>
          </div>
        </div>

        <div class="bes-admin-note">
          <strong>Not:</strong> Temsilciyi hangi seviyede göstermek istiyorsan o seviyeyi seç (Şube/İl/İlçe/İşyeri).
          İlçe yoksa boş bırak — temsilci İl’de görünür.
        </div>

        <div id="bes-admin-msg" class="bes-admin-msg"></div>
      </div>
    </div>
    <?php
  }

  public function admin_page_quick_add() {
    if(!current_user_can('edit_posts')) wp_die('Yetki yok.');
    $ok = isset($_GET['ok']) ? sanitize_text_field($_GET['ok']) : '';
    $err = isset($_GET['err']) ? sanitize_text_field($_GET['err']) : '';
    ?>
    <div class="wrap bes-admin-wrap">
      <h1>Hızlı Temsilci Ekle</h1>
      <p class="description">Tek ekranda temsilci ekle. Kaydettiğinde temsilci, seçtiğin birime göre filtrede anında görünür.</p>

      <?php if($ok): ?><div class="notice notice-success"><p><?php echo esc_html($ok); ?></p></div><?php endif; ?>
      <?php if($err): ?><div class="notice notice-error"><p><?php echo esc_html($err); ?></p></div><?php endif; ?>

      <div class="bes-admin-card">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <?php wp_nonce_field('bes_quick_add','bes_quick_add_nonce'); ?>
          <input type="hidden" name="action" value="bes_quick_add">
          <input type="hidden" name="bes_photo_id" id="bes_photo_id" value="0">

          <div class="bes-form-grid">
            <div class="bes-admin-field">
              <label>Ad</label>
              <input type="text" name="bes_ad" required placeholder="Örn: Ahmet">
            </div>
            <div class="bes-admin-field">
              <label>Soyad</label>
              <input type="text" name="bes_soyad" required placeholder="Örn: Yılmaz">
            </div>
            <div class="bes-admin-field" style="grid-column:1/3">
              <label>İşyeri (Bakanlık → Kurum → Bölge/İl Md → İşyeri)</label>
              <?php $all_work = get_terms(['taxonomy'=>self::TAX_WORK,'hide_empty'=>false,'orderby'=>'name','order'=>'ASC']); ?>
              <select name="bes_workplace_leaf">
                <option value="">Seç (isteğe bağlı)</option>
                <?php foreach($all_work as $tw): 
                  if(is_wp_error($tw)) continue;
                  // sadece yaprak termleri tercih et (çocuğu yoksa)
                  $children = get_terms(['taxonomy'=>self::TAX_WORK,'hide_empty'=>false,'parent'=>$tw->term_id,'fields'=>'ids','number'=>1]);
                  if(!empty($children)) continue;
                  $path = $this->workplace_path($tw->term_id);
                ?>
                  <option value="<?php echo esc_attr($tw->term_id); ?>"><?php echo esc_html($path); ?></option>
                <?php endforeach; ?>
              </select>
              <p class="description">Listede yoksa: <a href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy='.self::TAX_WORK.'&post_type='.self::CPT)); ?>">İşyerleri</a> menüsünden ekle.</p>
            </div>
            <div class="bes-admin-field">
              <label>Ünvan</label>
              <input type="text" name="bes_unvan" placeholder="Örn: İşyeri Temsilcisi / Şube Başkanı">
            </div>
            <div class="bes-admin-field" style="grid-column:1/3">
              <label>Sendikadaki Görev(ler)</label>
              <?php $all_roles = get_terms(['taxonomy'=>self::TAX_ROLE,'hide_empty'=>false,'orderby'=>'name','order'=>'ASC']); ?>
              <div style="display:flex;flex-wrap:wrap;gap:10px">
                <?php foreach($all_roles as $r): ?>
                  <label style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid rgba(0,0,0,.08);border-radius:14px;background:#fff">
                    <input type="checkbox" name="bes_role_ids[]" value="<?php echo esc_attr($r->term_id); ?>">
                    <span><?php echo esc_html($r->name); ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <p class="description">İstersen sonradan temsilci düzenleme ekranından yeni görev ekleyebilirsin.</p>
            </div>
            <div class="bes-admin-field">
              <label>Telefon</label>
              <input type="text" name="bes_tel" placeholder="05xx xxx xx xx">
            </div>
            <div class="bes-admin-field">
              <label>WhatsApp (opsiyonel)</label>
              <input type="text" name="bes_wa" placeholder="905xxxxxxxxx veya link">
            </div>
          </div>

          <div class="bes-divider"></div>

          <div class="bes-admin-grid">
            <div class="bes-admin-field">
              <label>Şube</label>
              <select id="bes-q0" name="bes_q0" required>
                <option value="">Şube seç</option>
                <?php foreach($this->get_root_terms() as $t): ?>
                  <option value="<?php echo esc_attr($t->term_id); ?>"><?php echo esc_html($t->name); ?></option>
                <?php endforeach; ?>
              </select>
              <div class="bes-miniadd">
                <input type="text" id="bes-qadd0" placeholder="Yeni Şube">
                <button type="button" class="button" data-qadd-level="0">+</button>
              </div>
            </div>

            <div class="bes-admin-field">
              <label>İl (opsiyonel)</label>
              <select id="bes-q1" name="bes_q1" disabled><option value="">İl seç</option></select>
              <div class="bes-miniadd">
                <input type="text" id="bes-qadd1" placeholder="Yeni İl">
                <button type="button" class="button" data-qadd-level="1" disabled>+</button>
              </div>
            </div>

            <div class="bes-admin-field">
              <label>İlçe (opsiyonel)</label>
              <select id="bes-q2" name="bes_q2" disabled><option value="">İlçe seç</option></select>
              <div class="bes-miniadd">
                <input type="text" id="bes-qadd2" placeholder="Yeni İlçe">
                <button type="button" class="button" data-qadd-level="2" disabled>+</button>
              </div>
            </div>

            <div class="bes-admin-field">
              <label>İşyeri (opsiyonel)</label>
              <select id="bes-q3" name="bes_q3" disabled><option value="">İşyeri seç</option></select>
              <div class="bes-miniadd">
                <input type="text" id="bes-qadd3" placeholder="Yeni İşyeri">
                <button type="button" class="button" data-qadd-level="3" disabled>+</button>
              </div>
            </div>
          </div>

          <div class="bes-admin-note"><strong>Fotoğraf:</strong> İşyeri temsilcileri için kartta fotoğraf gösterilir.</div>

          <div class="bes-photo-row">
            <button type="button" class="button" id="bes_pick_photo">Fotoğraf Seç</button>
            <button type="button" class="button" id="bes_remove_photo" style="display:none;">Kaldır</button>
            <span id="bes_photo_label" class="bes-photo-label">Seçilmedi</span>
          </div>

          <div class="bes-actions-row">
            <button type="submit" class="button button-primary button-hero">Kaydet</button>
            <label class="bes-check"><input type="checkbox" name="bes_keep" value="1" checked> Kaydettikten sonra bu sayfada kal (seri ekleme)</label>
          </div>
        </form>
      </div>
    </div>
    <?php
  }

  public function admin_page_import() {
    if(!current_user_can('edit_posts')) wp_die('Yetki yok.');
    $ok = isset($_GET['ok']) ? sanitize_text_field($_GET['ok']) : '';
    $err = isset($_GET['err']) ? sanitize_text_field($_GET['err']) : '';
    ?>
    <div class="wrap bes-admin-wrap">
      <h1>Toplu İçe Aktar (Excel / CSV)</h1>
      <p class="description">Excel’i <strong>CSV</strong> olarak dışa aktar ve buradan yükle. Sistem birimleri otomatik oluşturur.</p>

      <?php if($ok): ?><div class="notice notice-success"><p><?php echo esc_html($ok); ?></p></div><?php endif; ?>
      <?php if($err): ?><div class="notice notice-error"><p><?php echo esc_html($err); ?></p></div><?php endif; ?>

      <div class="bes-admin-card">
        <h2>Şablon</h2>
        <p>CSV başlıkları (ilk satır):</p>
        <code>ad,soyad,kurum,unvan,yetkiler,telefon,whatsapp,sube,il,ilce,isyeri,photo_url</code>
        <p class="description"><strong>photo_url</strong> opsiyonel.</p>

        <div class="bes-divider"></div>

        <h2>Yükle</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
          <?php wp_nonce_field('bes_import','bes_import_nonce'); ?>
          <input type="hidden" name="action" value="bes_import">
          <input type="file" name="bes_csv" accept=".csv,text/csv" required>
          <p class="description">Öneri: Excel → “CSV UTF-8” olarak kaydet.</p>
          <button type="submit" class="button button-primary">İçe Aktar</button>
        </form>
      </div>
    </div>
    <?php
  }

  private function redirect_admin($page_slug, $ok, $err){
    $url = admin_url('edit.php?post_type='.self::CPT.'&page='.$page_slug);
    if($ok) $url = add_query_arg('ok', rawurlencode($ok), $url);
    if($err) $url = add_query_arg('err', rawurlencode($err), $url);
    wp_safe_redirect($url); exit;
  }

  public function handle_quick_add() {
    if(!current_user_can('edit_posts')) wp_die('Yetki yok.');
    if(!isset($_POST['bes_quick_add_nonce']) || !wp_verify_nonce($_POST['bes_quick_add_nonce'],'bes_quick_add')) wp_die('Nonce hatası.');

    $ad = isset($_POST['bes_ad']) ? sanitize_text_field($_POST['bes_ad']) : '';
    $soyad = isset($_POST['bes_soyad']) ? sanitize_text_field($_POST['bes_soyad']) : '';
    $kurum = isset($_POST['bes_kurum']) ? sanitize_text_field($_POST['bes_kurum']) : '';
    $work_leaf = isset($_POST['bes_workplace_leaf']) ? intval($_POST['bes_workplace_leaf']) : 0;
    $role_ids = isset($_POST['bes_role_ids']) && is_array($_POST['bes_role_ids']) ? array_map('intval', $_POST['bes_role_ids']) : [];
    $yetkiler = isset($_POST['bes_yetkiler']) ? sanitize_text_field($_POST['bes_yetkiler']) : '';
    $name = trim($ad.' '.$soyad);
    $unvan_term = isset($_POST['bes_unvan_term']) ? intval($_POST['bes_unvan_term']) : 0;
    $unvan = isset($_POST['bes_unvan']) ? sanitize_text_field($_POST['bes_unvan']) : '';
    $tel = isset($_POST['bes_tel']) ? sanitize_text_field($_POST['bes_tel']) : '';
    $wa = isset($_POST['bes_wa']) ? sanitize_text_field($_POST['bes_wa']) : '';
    $photo_id = isset($_POST['bes_photo_id']) ? (int)$_POST['bes_photo_id'] : 0;

    $q0 = isset($_POST['bes_q0']) ? (int)$_POST['bes_q0'] : 0;
    $q1 = isset($_POST['bes_q1']) ? (int)$_POST['bes_q1'] : 0;
    $q2 = isset($_POST['bes_q2']) ? (int)$_POST['bes_q2'] : 0;
    $q3 = isset($_POST['bes_q3']) ? (int)$_POST['bes_q3'] : 0;

    if($name==='' || !$q0) $this->redirect_admin('bes-quick-add','', 'Ad, Soyad ve Şube zorunludur.');

    $term_id = $this->deepest_term_id($q0,$q1,$q2,$q3);
    $post_id = wp_insert_post(['post_type'=>self::CPT,'post_status'=>'publish','post_title'=>$name], true);
    if(is_wp_error($post_id)) $this->redirect_admin('bes-quick-add','', $post_id->get_error_message());

    if($ad) update_post_meta($post_id,'_bes_ad',$ad);
    if($soyad) update_post_meta($post_id,'_bes_soyad',$soyad);
    if($kurum) update_post_meta($post_id,'_bes_kurum',$kurum);
    if($unvan) update_post_meta($post_id,'_bes_unvan',$unvan);
    if($yetkiler) update_post_meta($post_id,'_bes_yetkiler',$yetkiler);
    if($tel) update_post_meta($post_id,'_bes_telefon',$tel);
    if($wa) update_post_meta($post_id,'_bes_whatsapp',$wa);
    if($term_id) wp_set_object_terms($post_id, [$term_id], self::TAX, false);

    if($work_leaf>0) wp_set_object_terms($post_id, [$work_leaf], self::TAX_WORK, false);
    if(!empty($role_ids)) wp_set_object_terms($post_id, $role_ids, self::TAX_ROLE, false);

    if($photo_id>0) set_post_thumbnail($post_id, $photo_id);

    $this->delete_province_cache();

    $keep = isset($_POST['bes_keep']) ? 1 : 0;
    if($keep) $this->redirect_admin('bes-quick-add','Temsilci eklendi.','');
    wp_safe_redirect(get_edit_post_link($post_id,'')); exit;
  }

  private function get_csv($row, $map, $key){
    $key = trim(strtolower($key));
    if(!isset($map[$key])) return '';
    $i = (int)$map[$key];
    return isset($row[$i]) ? trim((string)$row[$i]) : '';
  }

  public function handle_import() {
    if(!current_user_can('edit_posts')) wp_die('Yetki yok.');
    if(!isset($_POST['bes_import_nonce']) || !wp_verify_nonce($_POST['bes_import_nonce'],'bes_import')) wp_die('Nonce hatası.');
    if(!isset($_FILES['bes_csv']) || empty($_FILES['bes_csv']['tmp_name'])) $this->redirect_admin('bes-import','', 'CSV dosyası bulunamadı.');

    $fh = fopen($_FILES['bes_csv']['tmp_name'], 'r');
    if(!$fh) $this->redirect_admin('bes-import','', 'CSV açılamadı.');

    $first = fgets($fh);
    if($first===false){ fclose($fh); $this->redirect_admin('bes-import','', 'CSV boş.'); }
    $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
    $headers = str_getcsv($first);
    $map = [];
    foreach($headers as $i=>$h){ $map[trim(strtolower($h))] = $i; }
    foreach(['sube'] as $r){ if(!isset($map[$r])) { fclose($fh); $this->redirect_admin('bes-import','', 'Eksik başlık: '.$r); } }

    $ok=0; $fail=0;
    while(($row=fgetcsv($fh))!==false){
      $ad = $this->get_csv($row,$map,'ad');
      $soyad = $this->get_csv($row,$map,'soyad');
      $ad_soyad = $this->get_csv($row,$map,'ad_soyad');
      if($ad==='' && $ad_soyad!==''){
        $parts = preg_split('/\s+/', trim($ad_soyad));
        $ad = $parts[0] ?? '';
        $soyad = count($parts)>1 ? $parts[count($parts)-1] : '';
      }
      $full_name = trim($ad.' '.$soyad);
      $sube_name = $this->get_csv($row,$map,'sube');
      if($full_name==='' || $sube_name===''){ $fail++; continue; }

      $kurum = $this->get_csv($row,$map,'kurum');
      $unvan = $this->get_csv($row,$map,'unvan');
      $yetkiler = $this->get_csv($row,$map,'yetkiler');
      $tel = $this->get_csv($row,$map,'telefon');
      $wa = $this->get_csv($row,$map,'whatsapp');
      $il = $this->get_csv($row,$map,'il');
      $ilce = $this->get_csv($row,$map,'ilce');
      $isyeri = $this->get_csv($row,$map,'isyeri');
      $photo_url = $this->get_csv($row,$map,'photo_url');

      $sube_id = $this->ensure_term($sube_name, 0);
      if(!$sube_id){ $fail++; continue; }
      $il_id=0; $ilce_id=0; $isyeri_id=0;
      if($il!=='') $il_id = $this->ensure_term($il, $sube_id);
      if($ilce!=='' && $il_id) $ilce_id = $this->ensure_term($ilce, $il_id);
      if($isyeri!=='' && $ilce_id) $isyeri_id = $this->ensure_term($isyeri, $ilce_id);

      $term_id = $this->deepest_term_id($sube_id,$il_id,$ilce_id,$isyeri_id);

      $post_id = wp_insert_post(['post_type'=>self::CPT,'post_status'=>'publish','post_title'=>$full_name], true);
      if(is_wp_error($post_id)){ $fail++; continue; }

      if($ad) update_post_meta($post_id,'_bes_ad', sanitize_text_field($ad));
      if($soyad) update_post_meta($post_id,'_bes_soyad', sanitize_text_field($soyad));
      if($kurum) update_post_meta($post_id,'_bes_kurum', sanitize_text_field($kurum));
      if($unvan) update_post_meta($post_id,'_bes_unvan', sanitize_text_field($unvan));
      if($yetkiler) update_post_meta($post_id,'_bes_yetkiler', sanitize_text_field($yetkiler));
      if($tel) update_post_meta($post_id,'_bes_telefon', sanitize_text_field($tel));
      if($wa) update_post_meta($post_id,'_bes_whatsapp', sanitize_text_field($wa));
      if($term_id) wp_set_object_terms($post_id, [$term_id], self::TAX, false);

      if($photo_url){
        $att = $this->sideload_image_to_post($photo_url, $post_id);
        if($att) set_post_thumbnail($post_id, $att);
      }

      $ok++;
    }
    fclose($fh);
    $this->delete_province_cache();
    $this->redirect_admin('bes-import', sprintf('İçe aktarma tamamlandı: %d kayıt eklendi, %d satır atlandı.', $ok, $fail), '');
  }

  public function register_meta_boxes() {
    add_meta_box(
      'bes_temsilci_bilgiler',
      'Temsilci Bilgileri (Kartta Gözükecek)',
      [$this, 'meta_box_bilgiler_html'],
      self::CPT,
      'normal',
      'high'
    );
    add_meta_box(
      'bes_temsilci_bilgiler',
      'Temsilci Bilgileri (Kartta Gözükecek)',
      [$this, 'meta_box_bilgiler_html'],
      self::CPT,
      'normal',
      'high'
    );
  }

  public function meta_box_birim_html($post){
    wp_nonce_field('bes_save_meta','bes_meta_nonce');
    $assigned = wp_get_post_terms($post->ID, self::TAX, ['fields'=>'ids']);
    $assigned_id = (!is_wp_error($assigned) && !empty($assigned)) ? (int)$assigned[0] : 0;

    $chain = [0,0,0,0];
    if($assigned_id){
      $t = get_term($assigned_id, self::TAX);
      $parents = [];
      while($t && !is_wp_error($t) && $t->parent){ $parents[] = (int)$t->parent; $t = get_term($t->parent, self::TAX); if(count($parents)>10) break; }
      $parents = array_reverse($parents);
      $path = array_merge($parents, [$assigned_id]);
      foreach($path as $i=>$term_id){ if($i>3) break; $chain[$i] = (int)$term_id; }
    }

    $roots = $this->get_root_terms();
    ?>
    <div class="bes-admin-field">
      <label><strong>Şube</strong></label>
      <select id="bes-m0" name="bes_m0">
        <option value="">Seç</option>
        <?php foreach($roots as $t): ?>
          <option value="<?php echo esc_attr($t->term_id); ?>" <?php selected($chain[0], $t->term_id); ?>><?php echo esc_html($t->name); ?></option>
        <?php endforeach; ?>
      </select>
      <div class="bes-miniadd">
        <input type="text" id="bes-addname0" placeholder="Yeni Şube">
        <button type="button" class="button" data-add-level="0">+</button>
      </div>
    </div>

    <div class="bes-admin-field">
      <label><strong>İl (opsiyonel)</strong></label>
      <select id="bes-m1" name="bes_m1" <?php echo $chain[0] ? '' : 'disabled'; ?>><option value=""><?php echo $chain[0] ? 'Seç' : 'Önce Şube seç'; ?></option></select>
      <div class="bes-miniadd">
        <input type="text" id="bes-addname1" placeholder="Yeni İl">
        <button type="button" class="button" data-add-level="1" disabled>+</button>
      </div>
    </div>

    <div class="bes-admin-field">
      <label><strong>İlçe (opsiyonel)</strong></label>
      <select id="bes-m2" name="bes_m2" <?php echo $chain[1] ? '' : 'disabled'; ?>><option value=""><?php echo $chain[1] ? 'Seç' : 'Önce İl seç'; ?></option></select>
      <div class="bes-miniadd">
        <input type="text" id="bes-addname2" placeholder="Yeni İlçe">
        <button type="button" class="button" data-add-level="2" disabled>+</button>
      </div>
    </div>

    <div class="bes-admin-field">
      <label><strong>İşyeri (opsiyonel)</strong></label>
      <select id="bes-m3" name="bes_m3" <?php echo $chain[2] ? '' : 'disabled'; ?>><option value=""><?php echo $chain[2] ? 'Seç' : 'Önce İlçe seç'; ?></option></select>
      <div class="bes-miniadd">
        <input type="text" id="bes-addname3" placeholder="Yeni İşyeri">
        <button type="button" class="button" data-add-level="3" disabled>+</button>
      </div>
    </div>

    <script>window.BES_PRESELECT = <?php echo wp_json_encode($chain); ?>;</script>
    <p class="description" style="margin-top:10px;">Temsilci, seçtiğin <strong>en alt seviyeye</strong> bağlanır.</p>
    <?php
  }

  public function meta_box_bilgiler_html($post){
    $ad = get_post_meta($post->ID,'_bes_ad', true);
    $soyad = get_post_meta($post->ID,'_bes_soyad', true);
    $unvan = get_post_meta($post->ID,'_bes_unvan', true);
    $tel = get_post_meta($post->ID,'_bes_telefon', true);
    $wa = get_post_meta($post->ID,'_bes_whatsapp', true);

    // İşyeri (kurum) seçimi
    $work_terms = wp_get_object_terms($post->ID, self::TAX_WORK, ['fields'=>'ids']);
    $w_leaf = (!is_wp_error($work_terms) && !empty($work_terms)) ? intval($work_terms[0]) : 0;

    $w_chain = [];
    if($w_leaf){
      $w_chain[] = $w_leaf;
      $p = get_term($w_leaf, self::TAX_WORK);
      while($p && !is_wp_error($p) && $p->parent){
        $w_chain[] = intval($p->parent);
        $p = get_term($p->parent, self::TAX_WORK);
      }
      $w_chain = array_reverse($w_chain); // root -> leaf
    }

    $w0 = $w_chain[0] ?? 0; // Bakanlık
    $w1 = $w_chain[1] ?? 0; // Kurum Merkezi
    $w2 = $w_chain[2] ?? 0; // Bölge/İl Md
    $w3 = $w_chain[3] ?? 0; // İşyeri Adı (leaf)

    $w0_terms = get_terms(['taxonomy'=>self::TAX_WORK,'hide_empty'=>false,'parent'=>0,'orderby'=>'name','order'=>'ASC']);
    $w1_terms = $w0 ? get_terms(['taxonomy'=>self::TAX_WORK,'hide_empty'=>false,'parent'=>$w0,'orderby'=>'name','order'=>'ASC']) : [];
    $w2_terms = $w1 ? get_terms(['taxonomy'=>self::TAX_WORK,'hide_empty'=>false,'parent'=>$w1,'orderby'=>'name','order'=>'ASC']) : [];
    $w3_terms = $w2 ? get_terms(['taxonomy'=>self::TAX_WORK,'hide_empty'=>false,'parent'=>$w2,'orderby'=>'name','order'=>'ASC']) : [];

    // Görevler (çoklu)
    $role_ids = wp_get_object_terms($post->ID, self::TAX_ROLE, ['fields'=>'ids']);
    if(is_wp_error($role_ids)) $role_ids = [];
    $all_roles = get_terms(['taxonomy'=>self::TAX_ROLE,'hide_empty'=>false,'orderby'=>'name','order'=>'ASC']);
    ?>
    <style>
      .bes-admin-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;max-width:980px}
      .bes-admin-grid label{font-weight:800;display:block;margin-bottom:6px}
      .bes-admin-grid input,.bes-admin-grid select{width:100%;max-width:100%}
      .bes-admin-section{margin-top:14px;padding-top:12px;border-top:1px solid rgba(0,0,0,.08)}
      .bes-inline{display:flex;gap:8px;align-items:center}
      .bes-inline input{flex:1}
      .bes-pill{display:inline-block;padding:6px 10px;border-radius:999px;background:rgba(0,0,0,.05);margin:4px 6px 0 0;font-weight:800;font-size:12px}
      .bes-role-list{display:flex;flex-wrap:wrap;gap:10px}
      .bes-role-item{display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid rgba(0,0,0,.08);border-radius:14px;background:#fff}
    </style>

    <div class="bes-admin-grid">
      <div>
        <label>Ad</label>
        <input type="text" name="bes_ad" value="<?php echo esc_attr($ad); ?>" placeholder="Örn: Ahmet">
      </div>
      <div>
        <label>Soyad</label>
        <input type="text" name="bes_soyad" value="<?php echo esc_attr($soyad); ?>" placeholder="Örn: Yılmaz">
      </div>

      <div class="bes-admin-section" style="grid-column:1/3">
        <label>İşyeri Hiyerarşisi (Bakanlık → Kurum Merkezi → Bölge/İl Md → İşyeri)</label>
        <div class="bes-admin-grid" style="max-width:none">
          <div>
            <label>Bakanlık</label>
            <select id="bes-w0" name="bes_w0">
              <option value="">Seç</option>
              <?php foreach($w0_terms as $t): ?>
                <option value="<?php echo esc_attr($t->term_id); ?>" <?php selected($w0, $t->term_id); ?>><?php echo esc_html($t->name); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="bes-inline" style="margin-top:8px">
              <input type="text" id="bes-wadd0" placeholder="Yeni Bakanlık">
              <button type="button" class="button" id="bes-waddbtn0" data-level="0">Ekle</button>
            </div>
          </div>

          <div>
            <label>Kurum Merkezi</label>
            <select id="bes-w1" name="bes_w1" <?php echo $w0 ? '' : 'disabled'; ?>>
              <option value="">Seç</option>
              <?php foreach($w1_terms as $t): ?>
                <option value="<?php echo esc_attr($t->term_id); ?>" <?php selected($w1, $t->term_id); ?>><?php echo esc_html($t->name); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="bes-inline" style="margin-top:8px">
              <input type="text" id="bes-wadd1" placeholder="Yeni Kurum Merkezi" <?php echo $w0 ? '' : 'disabled'; ?>>
              <button type="button" class="button" id="bes-waddbtn1" data-level="1" <?php echo $w0 ? '' : 'disabled'; ?>>Ekle</button>
            </div>
          </div>

          <div>
            <label>Bölge / İl Müdürlüğü</label>
            <select id="bes-w2" name="bes_w2" <?php echo $w1 ? '' : 'disabled'; ?>>
              <option value="">Seç</option>
              <?php foreach($w2_terms as $t): ?>
                <option value="<?php echo esc_attr($t->term_id); ?>" <?php selected($w2, $t->term_id); ?>><?php echo esc_html($t->name); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="bes-inline" style="margin-top:8px">
              <input type="text" id="bes-wadd2" placeholder="Yeni Bölge/İl Md" <?php echo $w1 ? '' : 'disabled'; ?>>
              <button type="button" class="button" id="bes-waddbtn2" data-level="2" <?php echo $w1 ? '' : 'disabled'; ?>>Ekle</button>
            </div>
          </div>

          <div>
            <label>İşyeri Adı</label>
            <select id="bes-w3" name="bes_workplace_leaf" <?php echo $w2 ? '' : 'disabled'; ?>>
              <option value="">Seç</option>
              <?php foreach($w3_terms as $t): ?>
                <option value="<?php echo esc_attr($t->term_id); ?>" <?php selected($w3, $t->term_id); ?>><?php echo esc_html($t->name); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="bes-inline" style="margin-top:8px">
              <input type="text" id="bes-wadd3" placeholder="Yeni İşyeri" <?php echo $w2 ? '' : 'disabled'; ?>>
              <button type="button" class="button" id="bes-waddbtn3" data-level="3" <?php echo $w2 ? '' : 'disabled'; ?>>Ekle</button>
            </div>
          </div>
        </div>
        <p class="description">Örnek: Tarım Bakanlığı - DSİ Genel Müdürlüğü - DSİ 5. Bölge Müdürlüğü - DSİ 51. Şube Müdürlüğü</p>
      </div>

      <div>
        <label>Ünvan</label>
        <input type="text" name="bes_unvan" value="<?php echo esc_attr($unvan); ?>" placeholder="Örn: Şube Başkanı">
      </div>

      <div>
        <label>Telefon</label>
        <input type="text" name="bes_telefon" value="<?php echo esc_attr($tel); ?>" placeholder="05xx...">
      </div>

      <div>
        <label>WhatsApp</label>
        <input type="text" name="bes_whatsapp" value="<?php echo esc_attr($wa); ?>" placeholder="https://wa.me/...">
      </div>

      <div class="bes-admin-section" style="grid-column:1/3">
        <label>Sendikadaki Görev(ler)</label>
        <div class="bes-role-list">
          <?php foreach($all_roles as $r): ?>
            <label class="bes-role-item">
              <input type="checkbox" name="bes_role_ids[]" value="<?php echo esc_attr($r->term_id); ?>" <?php checked(in_array($r->term_id, $role_ids)); ?>>
              <span><?php echo esc_html($r->name); ?></span>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="bes-inline" style="margin-top:10px;max-width:520px">
          <input type="text" id="bes-role-add" placeholder="Yeni görev ekle (örn: İl Temsilcisi)">
          <button type="button" class="button" id="bes-role-addbtn">Ekle</button>
        </div>
        <p class="description">Görev ekledikten sonra listeden seçebilirsin.</p>
      </div>
    </div>

    <p><strong>Fotoğraf:</strong> Öne çıkan görsel kartta fotoğraf olarak görünür.</p>
    <?php
  }

  public function save_meta($post_id, $post){
    if($post->post_type!==self::CPT) return;
    if(!isset($_POST['bes_meta_nonce']) || !wp_verify_nonce($_POST['bes_meta_nonce'],'bes_save_meta')) return;
    if(defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if(!current_user_can('edit_post',$post_id)) return;

    $ad = isset($_POST['bes_ad']) ? sanitize_text_field($_POST['bes_ad']) : '';
    $soyad = isset($_POST['bes_soyad']) ? sanitize_text_field($_POST['bes_soyad']) : '';
    $kurum = isset($_POST['bes_kurum']) ? sanitize_text_field($_POST['bes_kurum']) : '';
    $unvan_term = isset($_POST['bes_unvan_term']) ? intval($_POST['bes_unvan_term']) : 0;
    $unvan = isset($_POST['bes_unvan']) ? sanitize_text_field($_POST['bes_unvan']) : '';
    $yetkiler = isset($_POST['bes_yetkiler']) ? sanitize_text_field($_POST['bes_yetkiler']) : '';
    $tel = isset($_POST['bes_telefon']) ? sanitize_text_field($_POST['bes_telefon']) : '';
    $wa = isset($_POST['bes_whatsapp']) ? sanitize_text_field($_POST['bes_whatsapp']) : '';

    $work_leaf = isset($_POST['bes_workplace_leaf']) ? intval($_POST['bes_workplace_leaf']) : 0;
    $role_ids = isset($_POST['bes_role_ids']) && is_array($_POST['bes_role_ids']) ? array_map('intval', $_POST['bes_role_ids']) : [];

    if($ad==='') delete_post_meta($post_id,'_bes_ad'); else update_post_meta($post_id,'_bes_ad',$ad);
    if($soyad==='') delete_post_meta($post_id,'_bes_soyad'); else update_post_meta($post_id,'_bes_soyad',$soyad);
    if($kurum==='') delete_post_meta($post_id,'_bes_kurum'); else update_post_meta($post_id,'_bes_kurum',$kurum);
    // Ünvan (taksonomi). Geri uyumluluk için meta da tutulabilir.
    if($unvan_term>0){
      wp_set_object_terms($post_id, [$unvan_term], self::TAX_UNVAN, false);
      delete_post_meta($post_id,'_bes_unvan');
    } else {
      wp_set_object_terms($post_id, [], self::TAX_UNVAN, false);
      if($unvan!==''){
        $found = get_term_by('name', $unvan, self::TAX_UNVAN);
        if($found && !is_wp_error($found)){
          wp_set_object_terms($post_id, [(int)$found->term_id], self::TAX_UNVAN, false);
          delete_post_meta($post_id,'_bes_unvan');
        } else {
          update_post_meta($post_id,'_bes_unvan',$unvan);
        }
      } else {
        delete_post_meta($post_id,'_bes_unvan');
      }
    }

    if($yetkiler==='') delete_post_meta($post_id,'_bes_yetkiler'); else update_post_meta($post_id,'_bes_yetkiler',$yetkiler);
    if($tel==='') delete_post_meta($post_id,'_bes_telefon'); else update_post_meta($post_id,'_bes_telefon',$tel);
    if($wa==='') delete_post_meta($post_id,'_bes_whatsapp'); else update_post_meta($post_id,'_bes_whatsapp',$wa);

    $m0 = isset($_POST['bes_m0']) ? (int)$_POST['bes_m0'] : 0;
    $m1 = isset($_POST['bes_m1']) ? (int)$_POST['bes_m1'] : 0;
    $m2 = isset($_POST['bes_m2']) ? (int)$_POST['bes_m2'] : 0;
    $m3 = isset($_POST['bes_m3']) ? (int)$_POST['bes_m3'] : 0;
    $selected = $this->deepest_term_id($m0,$m1,$m2,$m3);
    if($selected) wp_set_object_terms($post_id, [$selected], self::TAX, false);
    else wp_set_object_terms($post_id, [], self::TAX, false);

    // İşyeri & görevler
    if($work_leaf>0) wp_set_object_terms($post_id, [$work_leaf], self::TAX_WORK, false);
    else wp_set_object_terms($post_id, [], self::TAX_WORK, false);

    if(!empty($role_ids)) wp_set_object_terms($post_id, $role_ids, self::TAX_ROLE, false);
    else wp_set_object_terms($post_id, [], self::TAX_ROLE, false);

    $this->delete_province_cache();
  }

  public function frontend_enqueue_assets(){
    global $post;
    if(!($post instanceof WP_Post)) return;
    if(!has_shortcode($post->post_content,'bes_temsilciler')) return;

    $ver = '1.7.8';
    wp_register_style('bes-temsilciler', plugins_url('assets/bes-temsilciler.css', __FILE__), [], $ver);
    wp_register_script('bes-temsilciler', plugins_url('assets/bes-temsilciler.js', __FILE__), ['jquery'], $ver, true);
    wp_enqueue_style('bes-temsilciler');
    wp_enqueue_script('bes-temsilciler');

    wp_localize_script('bes-temsilciler','BES_T',[
      'ajaxurl'=>admin_url('admin-ajax.php'),
      'nonce'=>wp_create_nonce(self::NONCE_ACTION),
    ]);
  }

  public function shortcode($atts){
    $roots = $this->get_root_terms();
    ob_start(); ?>
    <div class="bes-wrap" data-max-depth="3">
      <div class="bes-top">
        <div class="bes-top-left">
          <div class="bes-title">Temsilciler</div>
          <div class="bes-subtitle">Şube / İl / İlçe / İşyeri filtreleyerek temsilcileri bul.</div>
        </div>
        <div class="bes-top-right">
          <div class="bes-search"><input type="text" id="bes-q" placeholder="Ad, soyad, ünvan veya işyeri ara..."></div>
        </div>
      </div>

      <div class="bes-map-wrap">
        <div class="bes-map-title">Türkiye Haritası (İl Bazında Temsilci Sayısı)</div>
        <div class="bes-map-card">
          <div id="bes-tr-map" class="bes-tr-map"></div>
          <div class="bes-map-note">İlin üzerine gelince sayıyı görürsün; tıklarsan o ile göre filtrelenir.</div>
        </div>
      </div>

      <div class="bes-filters">
        <div class="bes-select">
          <label>Şube</label>
          <select id="bes-level-0">
            <option value="">Şube seç</option>
            <?php foreach($roots as $t): ?>
              <option value="<?php echo esc_attr($t->term_id); ?>"><?php echo esc_html($t->name); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="bes-select">
          <label>İl</label>
          <select id="bes-level-1" disabled><option value="">İl seç</option></select>
        </div>

        <div class="bes-select">
          <label>İlçe</label>
          <select id="bes-level-2" disabled><option value="">İlçe seç</option></select>
        </div>

        <div class="bes-select">
          <label>İşyeri</label>
          <select id="bes-level-3" disabled><option value="">İşyeri seç</option></select>
        </div>

        <button type="button" class="bes-reset" id="bes-reset">Sıfırla</button>
      </div>

      <div class="bes-status-row">
        <div class="bes-breadcrumb" id="bes-bc"></div>
        <div class="bes-status info" id="bes-status">Lütfen bir Şube seçin.</div>
      </div>

      <div class="bes-skeleton" id="bes-skeleton" style="display:none;">
        <?php for($i=0;$i<6;$i++): ?><div class="bes-skel-card"></div><?php endfor; ?>
      </div>

      <div class="bes-grid" id="bes-results"></div>
      <div class="bes-load-row" id="bes-load-row" style="display:none;">
        <button type="button" class="bes-load-more" id="bes-load-more">Daha fazla göster</button>
      </div>
    </div>
    <script>
      window.BES_T_MAP = window.BES_T_MAP || {};
      BES_T_MAP.data = <?php echo wp_json_encode($this->get_province_counts()); ?>;
      BES_T_MAP.names = <?php echo wp_json_encode($this->province_code_name_map()); ?>;
    </script>
    <?php return ob_get_clean();
  }

  public function ajax_get_children(){
    check_ajax_referer(self::NONCE_ACTION,'nonce');
    $parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : 0;
    $terms = get_terms(['taxonomy'=>self::TAX,'hide_empty'=>false,'parent'=>$parent_id,'orderby'=>'name','order'=>'ASC']);
    if(is_wp_error($terms)) wp_send_json_error(['message'=>'Birimler alınamadı.']);
    $payload = array_map(function($t){ return ['id'=>$t->term_id,'name'=>$t->name]; }, $terms);
    wp_send_json_success(['items'=>$payload,'count'=>count($payload)]);
  }

  public function ajax_create_term(){
    check_ajax_referer(self::NONCE_ACTION,'nonce');
    if(!current_user_can('manage_categories')) wp_send_json_error(['message'=>'Yetki yok.']);
    $name = isset($_POST['name']) ? $this->sanitize_term_name($_POST['name']) : '';
    $parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : 0;
    if($name==='') wp_send_json_error(['message'=>'İsim boş olamaz.']);
    $v = $this->validate_parent_depth_for_new_term($parent_id);
    if(!$v['ok']) wp_send_json_error(['message'=>$v['message']]);
    $res = wp_insert_term($name, self::TAX, ['parent'=>$parent_id]);
    if(is_wp_error($res)) wp_send_json_error(['message'=>$res->get_error_message()]);
    wp_send_json_success(['id'=>(int)$res['term_id'],'name'=>$name]);
  }

  private function workplace_label($post_id){
    $terms = wp_get_object_terms($post_id, self::TAX_WORK, ['fields'=>'all']);
    if(is_wp_error($terms) || empty($terms)) return '';
    // pick the deepest term (leaf)
    $leaf = $terms[0];
    foreach($terms as $t){
      if($this->term_depth($t->term_id) > $this->term_depth($leaf->term_id)) $leaf = $t;
    }
    $names = [];
    $cur = $leaf;
    while($cur && !is_wp_error($cur)){
      $names[] = $cur->name;
      if(!$cur->parent) break;
      $cur = get_term((int)$cur->parent, self::TAX_WORK);
    }
    $names = array_reverse($names); // root -> leaf
    return implode(' - ', $names);
  }

  private function roles_label($post_id){
    $terms = wp_get_object_terms($post_id, self::TAX_ROLE, ['fields'=>'names']);
    if(is_wp_error($terms) || empty($terms)) return '';
    return implode(', ', $terms);
  }

  public function ajax_get_temsilciler(){
    check_ajax_referer(self::NONCE_ACTION,'nonce');

    $il_name = isset($_POST['il_name']) ? sanitize_text_field(wp_unslash($_POST['il_name'])) : '';
    $l0 = isset($_POST['l0']) ? (int)$_POST['l0'] : 0;
    $l1 = isset($_POST['l1']) ? (int)$_POST['l1'] : 0;
    $l2 = isset($_POST['l2']) ? (int)$_POST['l2'] : 0;
    $l3 = isset($_POST['l3']) ? (int)$_POST['l3'] : 0;
    $page = isset($_POST['page']) ? max(1, (int)$_POST['page']) : 1;
    $per_page = isset($_POST['per_page']) ? (int)$_POST['per_page'] : 24;
    $per_page = max(6, min($per_page, 100));
    $search = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';

    $term_ids = [];

    if($il_name !== ''){
      // Find all "İl" terms with this name under any Şube (depth=1: parent has parent=0)
      $terms = get_terms([
        'taxonomy'=>self::TAX,
        'hide_empty'=>false,
        'search'=>$il_name,
      ]);
      $needle = $this->bes_norm_tr($il_name);
      if(!is_wp_error($terms)){
        foreach($terms as $t){
          if($this->bes_norm_tr($t->name) !== $needle) continue;
          if((int)$t->parent === 0) continue; // not an İl
          $p = get_term((int)$t->parent, self::TAX);
          if($p && !is_wp_error($p) && (int)$p->parent === 0){
            $term_ids[] = (int)$t->term_id;
          }
        }
      }
      $term_ids = array_values(array_unique($term_ids));
      if(!$term_ids) wp_send_json_success(['items'=>[],'count'=>0,'total'=>0,'page'=>$page,'pages'=>0]);
    }else{
      $selected = $this->deepest_term_id($l0,$l1,$l2,$l3);
      if(!$selected) wp_send_json_success(['items'=>[],'count'=>0,'total'=>0,'page'=>$page,'pages'=>0]);
      $term_ids = [(int)$selected];
    }

    $tax_query = [];
    if(count($term_ids) === 1){
      $tax_query[] = [ 'taxonomy'=>self::TAX, 'field'=>'term_id', 'terms'=>$term_ids, 'include_children'=>true ];
    }else{
      $or = ['relation'=>'OR'];
      foreach($term_ids as $tid){
        $or[] = [ 'taxonomy'=>self::TAX, 'field'=>'term_id', 'terms'=>[$tid], 'include_children'=>true ];
      }
      $tax_query[] = $or;
    }

    $meta_query = [];
    if($search !== ''){
      $meta_query = [
        'relation' => 'OR',
        [ 'key' => '_bes_ad', 'value' => $search, 'compare' => 'LIKE' ],
        [ 'key' => '_bes_soyad', 'value' => $search, 'compare' => 'LIKE' ],
        [ 'key' => '_bes_unvan', 'value' => $search, 'compare' => 'LIKE' ],
        [ 'key' => '_bes_kurum', 'value' => $search, 'compare' => 'LIKE' ],
        [ 'key' => '_bes_yetkiler', 'value' => $search, 'compare' => 'LIKE' ],
      ];
    }

    $q = new WP_Query([
      'post_type'=>self::CPT,
      'post_status'=>'publish',
      'posts_per_page'=>$per_page,
      'paged'=>$page,
      'tax_query'=>$tax_query,
      'orderby'=>'title',
      'order'=>'ASC',
      'fields'=>'ids',
      's'=>$search !== '' ? $search : '',
      'meta_query'=>$meta_query,
    ]);

    $items = [];
    if(!empty($q->posts)){
      foreach($q->posts as $id){
        $ad = get_post_meta($id,'_bes_ad',true);
        $soyad = get_post_meta($id,'_bes_soyad',true);
        $kurum = get_post_meta($id,'_bes_kurum',true);
        if(!$kurum) $kurum = $this->workplace_label($id);
        $unvan = $this->get_unvan_label($id);
        $yetkiler_meta = get_post_meta($id,'_bes_yetkiler',true);
        $yetkiler_role = $this->roles_label($id);
        $yetkiler = $yetkiler_meta ? $yetkiler_meta : $yetkiler_role;
        if($yetkiler_meta && $yetkiler_role){
          $a = array_map('trim', explode(',', $yetkiler_meta));
          $b = array_map('trim', explode(',', $yetkiler_role));
          $yetkiler = implode(', ', array_values(array_unique(array_filter(array_merge($a,$b)))));
        }
        $tel = get_post_meta($id,'_bes_telefon',true);
        $wa = get_post_meta($id,'_bes_whatsapp',true);
        $photo = get_the_post_thumbnail_url($id,'medium') ?: '';
        $level_name = $this->get_level_label($id);

        $tel_clean = preg_replace('/\D+/','', (string)$tel);
        $tel_link = $tel_clean ? 'tel:+'.$tel_clean : '';
        $wa_link = $tel_clean ? 'https://wa.me/'.$tel_clean : ($wa ?: '');

        $items[] = [
          'id'=>$id,
          'ad'=>$ad?:'',
          'soyad'=>$soyad?:'',
          'name'=>get_the_title($id),
          'kurum'=>$kurum?:'',
          'unvan'=>$unvan?:'',
          'yetkiler'=>$yetkiler?:'',
          'telefon'=>$tel?:'',
          'telefon_link'=>$tel_link,
          'whatsapp'=>$wa_link,
          'photo'=>$photo,
          'level'=>$level_name?:'',
        ];
      }
    }

    $total = (int)$q->found_posts;
    $pages = $per_page > 0 ? (int)ceil($total / $per_page) : 0;
    wp_send_json_success(['items'=>$items,'count'=>count($items),'total'=>$total,'page'=>$page,'pages'=>$pages]);
  }

  public function tax_columns($columns){ $columns['bes_level']='Seviye'; return $columns; }
  public function tax_column_content($content,$column_name,$term_id){
    if($column_name!=='bes_level') return $content;
    return esc_html($this->depth_label($this->term_depth($term_id)));
  }

  public function ajax_get_work_children(){
    check_ajax_referer(self::NONCE_ACTION, 'nonce');
    $parent = isset($_POST['parent_id']) ? intval($_POST['parent_id']) : 0;
    $terms = get_terms([
      'taxonomy' => self::TAX_WORK,
      'hide_empty' => false,
      'parent' => $parent,
      'orderby' => 'name',
      'order' => 'ASC'
    ]);
    if(is_wp_error($terms)) wp_send_json_error(['message' => 'Term alınamadı']);
    $items = array_map(function($t){ return ['id'=>$t->term_id,'name'=>$t->name]; }, $terms);
    wp_send_json_success(['items'=>$items]);
  }

  public function ajax_add_work_term(){
    check_ajax_referer(self::NONCE_ACTION, 'nonce');
    if(!current_user_can('manage_categories')) wp_send_json_error(['message'=>'Yetki yok']);
    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $parent = isset($_POST['parent_id']) ? intval($_POST['parent_id']) : 0;
    if($name==='') wp_send_json_error(['message'=>'İsim zorunlu']);
    $res = wp_insert_term($name, self::TAX_WORK, ['parent'=>$parent]);
    if(is_wp_error($res)) wp_send_json_error(['message'=>$res->get_error_message()]);
    wp_send_json_success(['id'=>intval($res['term_id']),'name'=>$name]);
  }

  public function ajax_add_role_term(){
    check_ajax_referer(self::NONCE_ACTION, 'nonce');
    if(!current_user_can('manage_categories')) wp_send_json_error(['message'=>'Yetki yok']);
    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    if($name==='') wp_send_json_error(['message'=>'İsim zorunlu']);
    $res = wp_insert_term($name, self::TAX_ROLE);
    if(is_wp_error($res)) wp_send_json_error(['message'=>$res->get_error_message()]);
    wp_send_json_success(['id'=>intval($res['term_id']),'name'=>$name]);
  }

  public function ajax_add_unvan_term(){
    check_ajax_referer(self::NONCE_ACTION, 'nonce');
    if(!current_user_can('manage_categories')) wp_send_json_error(['message'=>'Yetki yok']);
    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $name = trim($name);
    if($name==='') wp_send_json_error(['message'=>'İsim boş olamaz']);
    $res = wp_insert_term($name, self::TAX_UNVAN);
    if(is_wp_error($res)) wp_send_json_error(['message'=>$res->get_error_message()]);
    wp_send_json_success(['id'=>intval($res['term_id']),'name'=>$name]);
  }

  private function workplace_path($term_id){
    $term = get_term($term_id, self::TAX_WORK);
    if(!$term || is_wp_error($term)) return '';
    $names = [$term->name];
    $p = $term->parent;
    while($p){
      $t = get_term($p, self::TAX_WORK);
      if(!$t || is_wp_error($t)) break;
      $names[] = $t->name;
      $p = $t->parent;
    }
    return implode(' - ', array_reverse($names));
  }

  private function role_names($post_id){
    $terms = wp_get_object_terms($post_id, self::TAX_ROLE, ['fields'=>'names']);
    if(is_wp_error($terms) || empty($terms)) return [];
    return array_values(array_filter(array_map('strval',$terms)));
  }

  private function unvan_term_id($post_id){
    $ids = wp_get_object_terms($post_id, self::TAX_UNVAN, ['fields'=>'ids']);
    if(!is_wp_error($ids) && !empty($ids)) return (int)$ids[0];
    return 0;
  }

  private function unvan_name($post_id){
    $terms = wp_get_object_terms($post_id, self::TAX_UNVAN, ['fields'=>'names']);
    if(!is_wp_error($terms) && !empty($terms)) return (string)$terms[0];
    // geriye dönük uyumluluk (eski meta)
    $meta = get_post_meta($post_id,'_bes_unvan',true);
    return $meta ? (string)$meta : '';
  }
  private function bes_norm_tr($s){
    $s = (string)$s;
    $s = trim($s);
    $map = ['İ'=>'i','I'=>'i','ı'=>'i','Ş'=>'s','ş'=>'s','Ğ'=>'g','ğ'=>'g','Ü'=>'u','ü'=>'u','Ö'=>'o','ö'=>'o','Ç'=>'c','ç'=>'c'];
    $s = strtr($s, $map);
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/\s+/', ' ', $s);
    return $s;
  }
}

new BES_Temsilciler_Plugin();
