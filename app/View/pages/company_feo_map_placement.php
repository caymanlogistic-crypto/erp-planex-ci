<?php
$fmtDateTime = static function ($value): string {
    if (!$value) return '‚Äî';
    $ts = strtotime((string)$value);
    return $ts === false ? e((string)$value) : date('d.m.Y H:i', $ts);
};
$fmtMass = static function ($value): string {
    $number = (float)$value;
    return number_format($number, abs($number - round($number)) < 0.0005 ? 0 : 3, ',', ' ');
};
?>
<style>
.feo-placement-head{margin-bottom:10px}
.feo-placement-table-card{display:block;min-width:0;border:0!important;box-shadow:none!important;background:transparent!important}
.feo-placement-table-card .table-scroll{overflow-x:hidden}
.feo-placement-toolbar{min-height:42px;height:auto;padding:6px 8px;gap:8px;flex-wrap:wrap}
.feo-placement-toolbar-summary{display:flex;align-items:center;gap:5px;flex-wrap:wrap}
.feo-placement-toolbar-summary .sep{color:var(--text-muted);font-weight:400}
.feo-placement-toolbar-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.feo-placement-hide-disabled{display:flex;align-items:center;gap:6px;min-height:30px;white-space:nowrap;font-size:11px;color:var(--text);cursor:pointer}
.feo-placement-hide-disabled input[type="checkbox"]{width:15px;height:15px;margin:0;accent-color:var(--accent)}
.feo-placement-table-card:has([data-placement-hide-disabled]:checked) .feo-placement-table tbody tr[data-placement-block-status="disabled"]{display:none}
.feo-placement-block-search-input{width:460px;min-width:320px;max-width:min(460px,80vw)}
.feo-placement-block-search-results{padding:8px;border-top:1px solid var(--line-soft);background:var(--surface-form)}
.feo-placement-block-search-results.is-hidden{display:none}
.feo-placement-search-summary{font-size:11px;color:var(--text-muted);margin-bottom:6px}
.feo-placement-search-table{width:100%;table-layout:fixed;min-width:0!important}
.feo-placement-search-table th,.feo-placement-search-table td{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.feo-placement-search-table tbody tr[data-placement-search-block-id]{cursor:pointer}
.feo-placement-search-table tbody tr[data-placement-search-block-id]:hover{background:var(--accent-bg)}
.feo-placement-table{width:100%;table-layout:fixed;min-width:0!important}
.feo-placement-table th,.feo-placement-table td{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.feo-placement-source-cell{white-space:normal!important;overflow-wrap:anywhere;word-break:normal;text-overflow:clip!important}
.feo-placement-table tbody tr[data-placement-block-id]{cursor:pointer}
.feo-placement-table tbody tr[data-placement-block-id]:hover{background:var(--accent-bg)}
.feo-placement-table tbody tr[data-placement-block-type="exclusion"],.feo-placement-table tbody tr[data-placement-block-type="exclusion"]:hover{background:var(--danger-bg)}
.feo-placement-status{font-weight:700}
.feo-placement-status--applied{color:var(--success,#2f7d32)}
.feo-placement-status--disabled{color:var(--danger,#b42318)}
.feo-placement-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.feo-placement-wide{grid-column:1/-1}
.feo-placement-radio-row{display:flex;gap:12px;flex-wrap:wrap}
.feo-placement-radio{display:flex;align-items:center;gap:6px;padding:8px 10px;border:1px solid var(--line-soft);background:var(--surface-form);cursor:pointer}
.feo-placement-textarea{min-height:180px;resize:vertical}
.feo-placement-source-file{display:none}
.feo-placement-source-file.is-active{display:block}
.feo-placement-manual.is-hidden{display:none}
.feo-placement-live-validation{margin-top:7px}
.feo-placement-live-validation.success{color:var(--success,#2f7d32)}
.feo-placement-live-validation.warn{display:block!important;width:100%;color:var(--danger,#b42318)}
[data-placement-create-error]:not(.is-hidden){display:block!important;width:100%}
.feo-placement-confirm-copy{display:grid;gap:7px}
.feo-placement-confirm-effect{border:1px solid var(--line-soft);background:var(--surface-form);padding:9px 10px}
.feo-placement-danger{color:var(--danger,#b42318)}
.feo-placement-repeat-title{font-weight:700;margin-bottom:5px}
.feo-placement-repeat-list{display:grid;gap:0;border-top:1px solid color-mix(in srgb,var(--danger,#b42318) 24%,transparent)}
.feo-placement-repeat-item{display:grid;grid-template-columns:92px minmax(0,1fr);gap:10px;padding:7px 0;border-bottom:1px solid color-mix(in srgb,var(--danger,#b42318) 18%,transparent)}
.feo-placement-repeat-id{font-weight:700;white-space:nowrap}
.feo-placement-repeat-blocks{display:grid;gap:3px;min-width:0}
.feo-placement-repeat-block{min-width:0;overflow-wrap:anywhere}
.feo-placement-detail-modal{width:min(760px,calc(100vw - 24px));max-width:760px}
.feo-placement-detail-modal .modal-title{min-width:0;overflow-wrap:anywhere}
.feo-placement-detail-modal-body{padding:0!important;overflow:hidden!important}
.feo-placement-detail-body{min-width:0;max-width:100%;overflow:hidden}
.feo-placement-detail-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0;margin:0;border-bottom:1px solid var(--line-soft)}
.feo-placement-detail-meta>div{min-width:0;border:0;border-right:1px solid var(--line-soft);border-bottom:1px solid var(--line-soft);background:var(--surface-form);padding:8px 10px}
.feo-placement-detail-meta>div:nth-child(2n){border-right:0}
.feo-placement-detail-meta>div:nth-last-child(-n+2){border-bottom:0}
.feo-placement-detail-meta span{display:block;color:var(--text-muted);font-size:11px;margin-bottom:2px}
.feo-placement-detail-meta b{display:block;min-width:0;overflow-wrap:anywhere;word-break:break-word}
.feo-placement-list{max-height:54vh;width:100%;max-width:100%;overflow-y:auto;overflow-x:hidden}
.feo-placement-list table{width:100%;max-width:100%;min-width:0!important;table-layout:fixed}
.feo-placement-list th,.feo-placement-list td{white-space:normal;overflow-wrap:anywhere;word-break:break-word}
@media(max-width:900px){
  .feo-placement-form-grid{grid-template-columns:1fr}
  .feo-placement-wide{grid-column:auto}
  .feo-placement-block-search-input{width:min(100%,86vw);min-width:240px}
  .feo-placement-detail-meta{grid-template-columns:1fr}
  .feo-placement-detail-meta>div{border-right:0;border-bottom:1px solid var(--line-soft)!important}
  .feo-placement-detail-meta>div:last-child{border-bottom:0!important}
}
</style>

<?php if (!empty($successFlash)): ?><div class="notice success"><?= e((string)$successFlash) ?></div><?php endif; ?>
<?php if (!empty($errorFlash)): ?><div class="notice warn"><?= e((string)$errorFlash) ?></div><?php endif; ?>
<?php if (!empty($dbError)): ?><div class="notice warn"><?= e((string)$dbError) ?></div><?php endif; ?>

<div class="page-head feo-placement-head">
  <div class="page-head-left">
    <h1 class="page-title">–†–∞–∑–º–µ—â–µ–Ω–∏–µ –Ω–∞ –∫–∞—Ä—Ç–µ</h1>
    <div class="page-summary"><span>–†–∞–±–æ—á–∏–π —Å–æ—Å—Ç–∞–≤ –∑–∞—è–≤–æ–∫ –§–≠–û, –ø–µ—Ä–µ–¥–∞–Ω–Ω—ã—Ö –≤ —Ä–∞–∑–º–µ—â–µ–Ω–∏–µ. –°–æ—Å—Ç–∞–≤ —Ä–∞—Å—Å—á–∏—Ç—ã–≤–∞–µ—Ç—Å—è –∏–∑ –ø—Ä–∏–º–µ–Ω—è–µ–º—ã—Ö –¥–æ–±–∞–≤–ª—è—é—â–∏—Ö –∏ –∏—Å–∫–ª—é—á–∞—é—â–∏—Ö –±–ª–æ–∫–æ–≤.</span></div>
  </div>
  <?php if (empty($dbError)): ?><div class="page-head-actions"><button type="button" class="btn btn-primary" data-placement-open-create>–°–æ–∑–¥–∞—Ç—å –±–ª–æ–∫</button></div><?php endif; ?>
</div>

<?php if (empty($dbError)): ?>
<?php if (empty($consistency['is_consistent'])): ?>
<div class="notice warn" data-placement-consistency-warning>
  <b>–ê–∫—Ç—É–∞–ª—å–Ω—ã–π —Å–æ—Å—Ç–∞–≤ –Ω–µ —Å–æ–≥–ª–∞—Å–æ–≤–∞–Ω —Å –ø—Ä–∏–º–µ–Ω—è–µ–º—ã–º–∏ –±–ª–æ–∫–∞–º–∏.</b>
  –í —Ä–∞–±–æ—á–µ–º –Ω–∞–±–æ—Ä–µ <?= (int)($consiste≤»="25ï·–§Ä¸¸ÄëÕΩ’…çïQï·–Ï(ÄÄÄÄÄÄÄÄÄÅÙ(ÄÄÄÄÄÄÄÄÄÅ•òÄ†ëÕΩ’…çïQï·–ÄÙÙÙÄúú§ÄëÕΩ’…çïQï·–ÄÙÄëÕΩ’…çïQÂ¡îÄÙÙÙÄùµÖπ’Ö∞úÄ¸ÄüäPúÄËÅÕ—…—Ω’¡¡ï»†ëÕΩ’…çïQÂ¡î§Ï(ÄÄÄÄÄÄÄÄ¸¯(ÄÄÄÄÄÄÄÄÒ—»ÅëÖ—Ñµ¡±Öçïµïπ–µâ±Ωç¨µ•êÙà¸ÙÄ°•π–§ëâ±Ωç≠lù•êùtÄ¸¯àÅëÖ—Ñµ¡±Öçïµïπ–µâ±Ωç¨µÕ—Ö—’ÃÙà¸ÙÄëÕ—Ö—’Õ¡¡±•ïêÄ¸ÄùÖ¡¡±•ïêúÄËÄùë•ÕÖâ±ïêúÄ¸¯àÅëÖ—Ñµ¡±Öçïµïπ–µâ±Ωç¨µ—Â¡îÙà¸ÙÅî†°Õ—…•πú§†ëâ±Ωç≠lùâ±Ωç≠}—Â¡îùtÄ¸¸Äúú§§Ä¸¯à¯(ÄÄÄÄÄÄÄÄÄÄÒ—ê¯å¸ÙÄ°•π–§ëâ±Ωç≠lù•êùtÄ¸¯Ω—ê¯(ÄÄÄÄÄÄÄÄÄÄÒ—ê¯¸ÙÅî†°Õ—…•πú§†ëâ±Ωç≠lùπÖµîùtÄ¸ËÄüäPú§§Ä¸¯Ω—ê¯(ÄÄÄÄÄÄÄÄÄÄÒ—ê¯¸ÙÄ†ëâ±Ωç≠lùâ±Ωç≠}—Â¡îùtÄ¸¸Äúú§ÄÙÙÙÄùÖëë•—•Ω∏úÄ¸ÄüBSB˚B«B√BÀBÔB◊B˜B„B‘úÄËÄüBcFBÎBÔF;FB◊B˜B„B‘úÄ¸¯Ω—ê¯(ÄÄÄÄÄÄÄÄÄÄÒ—ê¯¸ÙÄ°•π–§†ëâ±Ωç≠lù…ï≈’ïÕ—}çΩ’π–ùtÄ¸¸Ä¿§Ä¸¯Ω—ê¯(ÄÄÄÄÄÄÄÄÄÄÒ—ê¯¸ÙÅî†ëôµ—5ÖÕÃ†ëâ±Ωç≠lù—Ω—Ö±}µÖÕÕ}≠úùtÄ¸¸Ä¿§§Ä¸¯Ω—ê¯(ÄÄÄÄÄÄÄÄÄÄÒ—ê¸ÙÄëÕΩ’…çïQÂ¡îÄÙÙÙÄùµÖπ’Ö∞úÄ¸ÄúÅç±ÖÕÃÙâôïºµ¡±Öçïµïπ–µÕΩ’…çîµçï±∞àúÄËÄúúÄ¸¯Å—•—±îÙà¸ÙÅî†ëÕΩ’…çïQï·–§Ä¸¯à¯¸ÙÅî†ëÕΩ’…çïQï·–§Ä¸¯Ω—ê¯(ÄÄÄÄÄÄÄÄÄÄÒ—ê¯¸ÙÅî†ëôµ—Ö—ïQ•µî†ëâ±Ωç≠lùç…ïÖ—ïë}Ö–ùtÄ¸¸Åπ’±∞§§Ä¸¯É
‹ÄÒÕ¡Ö∏Åç±ÖÕÃÙâôïºµ¡±Öçïµïπ–µÕ—Ö—’ÃÄ¸ÙÄëÕ—Ö—’Õ¡¡±•ïêÄ¸Äùôïºµ¡±Öçïµïπ–µÕ—Ö—’Ã¥µÖ¡¡±•ïêúÄËÄùôïºµ¡±Öçïµïπ–µÕ—Ö—’Ã¥µë•ÕÖâ±ïêúÄ¸¯à¯¸ÙÄëÕ—Ö—’Õ¡¡±•ïêÄ¸ÄüBFB„BÛB◊B˜F?B◊FFF<úÄËÄüB{FBÎBÔF;FFGBÙúÄ¸¯ΩÕ¡Ö∏¯Ω—ê¯(ÄÄÄÄÄÄÄÄΩ—»¯(ÄÄÄÄÄÄ˝¡°¿ÅïπëôΩ…ïÖç†ÏÄ¸¯(ÄÄÄÄÄÄ˝¡°¿Å•òÄ†Ñëâ±Ωç≠Ã§ËÄ¸¯Ò—»¯Ò—êÅçΩ±Õ¡Ö∏Ùà‹àÅç±ÖÕÃÙâ—ï·–µµ’—ïêà˚BGBÔB˚BÎB‡ÉB◊F'FDÉB˜B‘ÉFB˚BﬂB”B√B˜F,∏Ω—ê¯Ω—»¯˝¡°¿Åïπë•òÏÄ¸¯(ÄÄÄÄÄÄΩ—âΩë‰¯(ÄÄÄÄΩ—Öâ±î¯(ÄÄΩë•ÿ¯(Ωë•ÿ¯(˝¡°¿Åïπë•òÏÄ¸¯((Òë•ÿÅ•êÙâôïºµ¡±Öçïµïπ–µç…ïÖ—îµµΩëÖ∞àÅç±ÖÕÃÙâµΩëÖ∞µΩŸï…±Ö‰àÅ…Ω±îÙâë•Ö±ΩúàÅÖ…•ÑµµΩëÖ∞Ùâ—…’îàÅëÖ—Ñµç±ΩÕîµΩ∏µΩŸï…±Ö‰ÙàƒàÅëÖ—Ñµç±ΩÕîµΩ∏µïÕçÖ¡îÙàƒà¯(ÄÄÒë•ÿÅç±ÖÕÃÙâµΩëÖ∞ÅµΩëÖ∞µ±úà¯(ÄÄÄÄÒë•ÿÅç±ÖÕÃÙâµΩëÖ∞µ°ïÖêà¯ÒÕ¡Ö∏Åç±ÖÕÃÙâµΩëÖ∞µ—•—±îà˚BwB˚BÀF/B‰ÉB«BÔB˚BËÉFB√BﬂBÛB◊F'B◊B˜B„F<ΩÕ¡Ö∏¯Òâ’——Ω∏Å—Â¡îÙââ’——Ω∏àÅç±ÖÕÃÙâµΩëÖ∞µç±ΩÕîàÅëÖ—Ñµç±ΩÕîµµΩëÖ∞Ùâôïºµ¡±Öçïµïπ–µç…ïÖ—îµµΩëÖ∞à¯ô—•µïÃÏΩâ’——Ω∏¯Ωë•ÿ¯(ÄÄÄÄÒë•ÿÅç±ÖÕÃÙâµΩëÖ∞µâΩë‰à¯(ÄÄÄÄÄÄÒôΩ…¥ÅëÖ—Ñµ¡±Öçïµïπ–µç…ïÖ—îµôΩ…¥ÅÖç—•Ω∏Ùà¸ÙÅÖ¡¡}’…∞†úΩçΩµ¡Öπ‰ΩôïºΩ…ï≈’ïÕ—ÃΩµÖ¿µ¡±Öçïµïπ–ΩÖπÖ±ÂÈîú§Ä¸¯àÅµï—°ΩêÙâ¡ΩÕ–àÅïπç—Â¡îÙâµ’±—•¡Ö…–ΩôΩ…¥µëÖ—Ñà¯(ÄÄÄÄÄÄÄÄ¸ÙÅçÕ…ô•ï±ê†§Ä¸¯(ÄÄÄÄÄÄÄÄÒë•ÿÅç±ÖÕÃÙâôïºµ¡±Öçïµïπ–µôΩ…¥µù…•êà¯(ÄÄÄÄÄÄÄÄÄÄÒë•ÿÅç±ÖÕÃÙâô•ï±êà¯Ò±Öâï∞Åç±ÖÕÃÙâô•ï±êµ±Öâï∞à˚BwB√BﬂBÀB√B˜B„B‘∞ÉB˜B◊B˚B«F?BﬂB√FB◊BÔF3B˜B¯Ω±Öâï∞¯Ò•π¡’–Åç±ÖÕÃÙâô•ï±êµ•π¡’–àÅ—Â¡îÙâ—ï·–àÅπÖµîÙâπÖµîàÅµÖ·±ïπù—†Ùà»‘‘à¯Ωë•ÿ¯(ÄÄÄÄÄÄÄÄÄÄÒë•ÿÅç±ÖÕÃÙâô•ï±êà¯Ò±Öâï∞Åç±ÖÕÃÙâô•ï±êµ±Öâï∞à˚BãB„B¸ÉB«BÔB˚BÎB¿Ω±Öâï∞¯Òë•ÿÅç±ÖÕÃÙâôïºµ¡±Öçïµïπ–µ…Öë•ºµ…Ω‹à¯Ò±Öâï∞Åç±ÖÕÃÙâôïºµ¡±Öçïµïπ–µ…Öë•ºà¯Ò•π¡’–Å—Â¡îÙâ…Öë•ºàÅπÖµîÙââ±Ωç≠}—Â¡îàÅŸÖ±’îÙâÖëë•—•Ω∏àÅç°ïç≠ïê¯ÉBSB˚B«B√BÀBÔB◊B˜B„B‘Ω±Öâï∞¯Ò±Öâï∞Åç±ÖÕÃÙâôïºµ¡±Öçïµïπ–µ…Öë•ºà¯Ò•π¡’–Å—Â¡îÙâ…Öë•ºàÅπÖµîÙââ±Ωç≠}—Â¡îàÅŸÖ±’îÙâï·ç±’Õ•Ω∏à¯ÉBcFBÎBÔF;FB◊B˜B„B‘Ω±Öâï∞¯Ωë•ÿ¯Ωë•ÿ¯(ÄÄÄÄÄÄÄÄÄÄÒë•ÿÅç±ÖÕÃÙâô•ï±êÅôïºµ¡±Öçïµïπ–µ›•ëîà¯Ò±Öâï∞Åç±ÖÕÃÙâô•ï±êµ±Öâï∞à˚BcFFB˚FB˜B„BËΩ±Öâï∞¯Òë•ÿÅç±ÖÕÃÙâôïºµ¡±Öçïµïπ–µ…Öë•ºµ…Ω‹à¯Ò±Öâï∞Åç±ÖÕÃÙâôïºµ¡±Öçïµïπ–µ…Öë•ºà¯Ò•π¡’–Å—Â¡îÙâ…Öë•ºàÅπÖµîÙâÕΩ’…çï}µΩëîàÅŸÖ±’îÙâµÖπ’Ö∞àÅç°ïç≠ïê¯ÉBÉFFB˜B˚B‰ÉBÀBÀB˚B–Ω±Öâï∞¯Ò±Öâï∞Åç±ÖÕÃÙâôïºµ¡±Öçïµïπ–µ…Öë•ºà¯Ò•π¡’–Å—Â¡îÙâ…Öë•ºàÅπÖµîÙâÕΩ’…çï}µΩëîàÅŸÖ±’îÙâô•±îà¯Åa1LÄºÅa1M`Ω±Öâï∞¯Ωë•ÿ¯Ωë•ÿ¯(ÄÄÄÄÄÄÄÄÄÄÒë•ÿÅç±ÖÕÃÙâô•ï±êÅôïºµ¡±Öçïµïπ–µ›•ëîÅôïºµ¡±Öçïµïπ–µµÖπ’Ö∞àÅëÖ—Ñµ¡±Öçïµïπ–µµÖπ’Ö∞¯(ÄÄÄÄÄÄÄÄÄÄÄÄÒ±Öâï∞Åç±ÖÕÃÙâô•ï±êµ±Öâï∞à˚B_B√F?BÀBÎB‡Ω±Öâï∞¯(ÄÄÄÄÄÄÄÄÄÄÄÄÒ—ï·—Ö…ïÑÅç±ÖÕÃÙâô•ï±êµ•π¡’–Åôïºµ¡±Öçïµïπ–µ—ï·—Ö…ïÑàÅπÖµîÙâ…ï≈’ïÕ—}•ëÃàÅëÖ—Ñµ¡±Öçïµïπ–µ•ëÃÅ¡±Öçï°Ω±ëï»ÙãBKFFB√BÀF3FB‘ÉBÔF;B«B˚B‰ÉFB◊BÎFFÉFÉF#B◊FFB„BﬂB˜B√FB˜F/BÛB‡Å%∏ÉBSBÔF<Å·çï∞ΩµÖ…≠ëΩ›∏∑FB√B«BÔB„FF,ÉB„FBˇB˚BÔF3BﬂFB◊FFF<ÉFB˚BÔF3BÎB¯ÉBˇB◊FBÀF/B‰ÉFFB˚BÔB«B◊F∏à¯Ω—ï·—Ö…ïÑ¯(ÄÄÄÄÄÄÄÄÄÄÄÄÒë•ÿÅç±ÖÕÃÙâô•ï±êµ°•π–à˚BsB˚B€B˜B¯ÉBÀFFB√BÀB„FF0ÉFFB√BﬂFÉB˜B◊FBÎB˚BÔF3BÎB¯ÉBﬂB√F?BÀB˚BË∞ÉBˇB˚FBÔB‘ÉBÀFFB√BÀBÎB‡ÉFBˇB„FB˚BËÉBÛB˚B€B˜B¯ÉBÀFFFB˜FF8ÉB”B˚BˇB˚BÔB˜F?FF0ÉB‡ÉFB◊B”B√BÎFB„FB˚BÀB√FF0∏Ωë•ÿ¯(ÄÄÄÄÄÄÄÄÄÄÄÄÒë•ÿÅç±ÖÕÃÙâπΩ—•çîÅ•Ãµ°•ëëï∏Åôïºµ¡±Öçïµïπ–µ±•ŸîµŸÖ±•ëÖ—•Ω∏àÅëÖ—Ñµ¡±Öçïµïπ–µ±•ŸîµŸÖ±•ëÖ—•Ω∏¯Ωë•ÿ¯(ÄÄÄÄÄÄÄÄÄÄΩë•ÿ¯(ÄÄÄÄÄÄÄÄÄÄÒë•ÿÅç±ÖÕÃÙâô•ï±êÅôïºµ¡±Öçïµïπ–µ›•ëîÅôïºµ¡±Öçïµïπ–µÕΩ’…çîµô•±îàÅëÖ—Ñµ¡±Öçïµïπ–µô•±î¯Ò±Öâï∞Åç±ÖÕÃÙâô•ï±êµ±Öâï∞à˚BìB√BÁBÏÅa1LÄºÅa1M`Ω±Öâï∞¯Ò•π¡’–Åç±ÖÕÃÙâô•ï±êµ•π¡’–àÅ—Â¡îÙâô•±îàÅπÖµîÙâÕΩ’…çï}ô•±îàÅÖççï¡–Ùàπ·±Ã∞π·±Õ‡à¯Òë•ÿÅç±ÖÕÃÙâô•ï±êµ°•π–à˚BáB„FFB◊BÛB¿ÉFB„FB√B◊FÉFB˚BÔF3BÎB¯ÉBˇB◊FBÀF/B‰ÉFFB˚BÔB«B◊FÉB‡ÉB˚FB«B„FB√B◊FÉFB√BÛB˚FFB˚F?FB◊BÔF3B˜F/B‘ÉBˇB˚FBÔB◊B”B˚BÀB√FB◊BÔF3B˜B˚FFB‡ÉFB˚BÀB˜B¯ÉB„B‹ÄÿÉFB„FF ∏Ωë•ÿ¯Ωë•ÿ¯(ÄÄÄÄÄÄÄÄΩë•ÿ¯(ÄÄÄÄÄÄΩôΩ…¥¯(ÄÄÄÄÄÄÒë•ÿÅç±ÖÕÃÙâπΩ—•çîÅ›Ö…∏Å•Ãµ°•ëëï∏àÅëÖ—Ñµ¡±Öçïµïπ–µç…ïÖ—îµï……Ω»¯Ωë•ÿ¯(ÄÄÄÄΩë•ÿ¯(ÄÄÄÄÒë•ÿÅç±ÖÕÃÙâµΩëÖ∞µôΩΩ–Å•ÃµÕ¡Öçïêà¯Òâ’——Ω∏Å—Â¡îÙââ’——Ω∏àÅç±ÖÕÃÙââ—∏Åâ—∏µù°ΩÕ–àÅëÖ—Ñµç±ΩÕîµµΩëÖ∞Ùâôïºµ¡±Öçïµïπ–µç…ïÖ—îµµΩëÖ∞à˚B{FBÛB◊B˜B¿Ωâ’——Ω∏¯Òâ’——Ω∏Å—Â¡îÙââ’——Ω∏àÅç±ÖÕÃÙââ—∏Åâ—∏µ¡…•µÖ…‰àÅëÖ—Ñµ¡±Öçïµïπ–µÖπÖ±ÂÈî˚BFB˚BÀB◊FB„FF0Ωâ’——Ω∏¯Ωë•ÿ¯(ÄÄΩë•ÿ¯(Ωë•ÿ¯((Òë•ÿÅ•êÙâôïºµ¡±Öçïµïπ–µçΩπô•…¥µµΩëÖ∞àÅç±ÖÕÃÙâµΩëÖ∞µΩŸï…±Ö‰àÅ…Ω±îÙâë•Ö±ΩúàÅÖ…•ÑµµΩëÖ∞Ùâ—…’îàÅëÖ—Ñµç±ΩÕîµΩ∏µΩŸï…±Ö‰Ùà¿àÅëÖ—Ñµç±ΩÕîµΩ∏µïÕçÖ¡îÙàƒà¯(ÄÄÒë•ÿÅç±ÖÕÃÙâµΩëÖ∞à¯(ÄÄÄÄÒë•ÿÅç±ÖÕÃÙâµΩëÖ∞µ°ïÖêà¯ÒÕ¡Ö∏Åç±ÖÕÃÙâµΩëÖ∞µ—•—±îàÅëÖ—Ñµ¡±Öçïµïπ–µçΩπô•…¥µ—•—±î˚BB˚B”FBÀB◊FB€B”B◊B˜B„B‘ΩÕ¡Ö∏¯Òâ’——Ω∏Å—Â¡îÙââ’——Ω∏àÅç±ÖÕÃÙâµΩëÖ∞µç±ΩÕîàÅëÖ—Ñµç±ΩÕîµµΩëÖ∞Ùâôïºµ¡±Öçïµïπ–µçΩπô•…¥µµΩëÖ∞à¯ô—•µïÃÏΩâ’——Ω∏¯Ωë•ÿ¯(ÄÄÄÄÒë•ÿÅç±ÖÕÃÙâµΩëÖ∞µâΩë‰à¯Òë•ÿÅç±ÖÕÃÙâôïºµ¡±Öçïµïπ–µçΩπô•…¥µçΩ¡‰àÅëÖ—Ñµ¡±Öçïµïπ–µçΩπô•…¥µçΩ¡‰¯Ωë•ÿ¯Òë•ÿÅç±ÖÕÃÙâπΩ—•çîÅ›Ö…∏Å•Ãµ°•ëëï∏àÅëÖ—Ñµ¡±Öçïµïπ–µçΩπô•…¥µï……Ω»¯Ωë•ÿ¯Ωë•ÿ¯(ÄÄÄÄÒë•ÿÅç±ÖÕÃÙâµΩëÖ∞µôΩΩ–Å•ÃµÕ¡Öçïêà¯Òâ’——Ω∏Å—Â¡îÙââ’——Ω∏àÅç±ÖÕÃÙââ—∏Åâ—∏µù°ΩÕ–àÅëÖ—Ñµç±ΩÕîµµΩëÖ∞Ùâôïºµ¡±Öçïµïπ–µçΩπô•…¥µµΩëÖ∞à˚B{FBÛB◊B˜B¿Ωâ’——Ω∏¯Òâ’——Ω∏Å—Â¡îÙââ’——Ω∏àÅç±ÖÕÃÙââ—∏Åâ—∏µ¡…•µÖ…‰àÅëÖ—Ñµ¡±Öçïµïπ–µçΩπô•…¥µÕ’âµ•–˚BB˚B”FBÀB◊FB”B„FF0Ωâ’——Ω∏¯Ωë•ÿ¯(ÄÄΩë•ÿ¯(Ωë•ÿ¯((Òë•ÿÅ•êÙâôïºµ¡±Öçïµïπ–µëï—Ö•∞µµΩëÖ∞àÅç±ÖÕÃÙâµΩëÖ∞µΩŸï…±Ö‰àÅ…Ω±îÙâë•Ö±ΩúàÅÖ…•ÑµµΩëÖ∞Ùâ—…’îàÅëÖ—Ñµç±ΩÕîµΩ∏µΩŸï…±Ö‰ÙàƒàÅëÖ—Ñµç±ΩÕîµΩ∏µïÕçÖ¡îÙàƒà¯(ÄÄÒë•ÿÅç±ÖÕÃÙâµΩëÖ∞ÅµΩëÖ∞µ±úÅôïºµ¡±Öçïµïπ–µëï—Ö•∞µµΩëÖ∞à¯(ÄÄÄÄÒë•ÿÅç±ÖÕÃÙâµΩëÖ∞µ°ïÖêà¯ÒÕ¡Ö∏Åç±ÖÕÃÙâµΩëÖ∞µ—•—±îàÅëÖ—Ñµ¡±Öçïµïπ–µëï—Ö•∞µ—•—±î˚BGBÔB˚BËÉFB√BﬂBÛB◊F'B◊B˜B„F<ΩÕ¡Ö∏¯Òâ’——Ω∏Å—Â¡îÙââ’——Ω∏àÅç±ÖÕÃÙâµΩëÖ∞µç±ΩÕîàÅëÖ—Ñµç±ΩÕîµµΩëÖ∞Ùâôïºµ¡±Öçïµïπ–µëï—Ö•∞µµΩëÖ∞à¯ô—•µïÃÏΩâ’——Ω∏¯Ωë•ÿ¯(ÄÄÄÄÒë•ÿÅç±ÖÕÃÙâµΩëÖ∞µâΩë‰Åôïºµ¡±Öçïµïπ–µëï—Ö•∞µµΩëÖ∞µâΩë‰à¯Òë•ÿÅç±ÖÕÃÙâôïºµ¡±Öçïµïπ–µëï—Ö•∞µâΩë‰àÅëÖ—Ñµ¡±Öçïµïπ–µëï—Ö•∞µâΩë‰¯Ωë•ÿ¯Òë•ÿÅç±ÖÕÃÙâπΩ—•çîÅ›Ö…∏Å•Ãµ°•ëëï∏àÅëÖ—Ñµ¡±Öçïµïπ–µëï—Ö•∞µï……Ω»¯Ωë•ÿ¯Ωë•ÿ¯(ÄÄÄÄÒë•ÿÅç±ÖÕÃÙâµΩëÖ∞µôΩΩ–Å•ÃµÕ¡Öçïêà¯Òâ’——Ω∏Å—Â¡îÙââ’——Ω∏àÅç±ÖÕÃÙââ—∏Åâ—∏µù°ΩÕ–àÅëÖ—Ñµç±ΩÕîµµΩëÖ∞Ùâôïºµ¡±Öçïµïπ–µëï—Ö•∞µµΩëÖ∞à˚B_B√BÎFF/FF0Ωâ’——Ω∏¯Òë•ÿÅç±ÖÕÃÙâµΩëÖ∞µôΩΩ–µÖç—•ΩπÃàÅëÖ—Ñµ¡±Öçïµïπ–µëï—Ö•∞µÖç—•ΩπÃ¯Ωë•ÿ¯Ωë•ÿ¯(ÄÄΩë•ÿ¯(Ωë•ÿ¯((Òë•ÿÅ•êÙâôïºµ¡±Öçïµïπ–µç’……ïπ–µµΩëÖ∞àÅç±ÖÕÃÙâµΩëÖ∞µΩŸï…±Ö‰àÅ…Ω±îÙâë•Ö±ΩúàÅÖ…•ÑµµΩëÖ∞Ùâ—…’îàÅëÖ—Ñµç±ΩÕîµΩ∏µΩŸï…±Ö‰ÙàƒàÅëÖ—Ñµç±ΩÕîµΩ∏µïÕçÖ¡îÙàƒà¯(ÄÄÒë•ÿÅç±ÖÕÃÙâµΩëÖ∞ÅµΩëÖ∞µ±úà¯(ÄÄÄÄÒë•ÿÅç±ÖÕÃÙâµΩëÖ∞µ°ïÖêà¯ÒÕ¡Ö∏Åç±ÖÕÃÙâµΩëÖ∞µ—•—±îà˚BCBÎFFB√BÔF3B˜F/B‘ÉBﬂB√F?BÀBÎB‡ÉB”BÔF<ÉBÎB√FFF,ΩÕ¡Ö∏¯Òâ’——Ω∏Å—Â¡îÙââ’——Ω∏àÅç±ÖÕÃÙâµΩëÖ∞µç±ΩÕîàÅëÖ—Ñµç±ΩÕîµµΩëÖ∞Ùâôïºµ¡±Öçïµïπ–µç’……ïπ–µµΩëÖ∞à¯ô—•µïÃÏΩâ’——Ω∏¯Ωë•ÿ¯(ÄÄÄÄÒë•ÿÅç±ÖÕÃÙâµΩëÖ∞µâΩë‰à¯Òë•ÿÅç±ÖÕÃÙâôïºµ¡±Öçïµïπ–µ±•Õ–àÅëÖ—Ñµ¡±Öçïµïπ–µç’……ïπ–µâΩë‰¯Ωë•ÿ¯Òë•ÿÅç±ÖÕÃÙâπΩ—•çîÅ›Ö…∏Å•Ãµ°•ëëï∏àÅëÖ—Ñµ¡±Öçïµïπ–µç’……ïπ–µï……Ω»¯Ωë•ÿ¯Ωë•ÿ¯(ÄÄÄÄÒë•ÿÅç±ÖÕÃÙâµΩëÖ∞µôΩΩ–à¯Òâ’——Ω∏Å—Â¡îÙââ’——Ω∏àÅç±ÖÕÃÙââ—∏Åâ—∏µù°ΩÕ–àÅëÖ—Ñµç±ΩÕîµµΩëÖ∞Ùâôïºµ¡±Öçïµïπ–µç’……ïπ–µµΩëÖ∞à˚B_B√BÎFF/FF0Ωâ’——Ω∏¯Ωë•ÿ¯(ÄÄΩë•ÿ¯(Ωë•ÿ¯((ÒÕç…•¡–¯)›•πëΩ‹π=}A159Q}=9%ÄÙÅÏ(ÄÅâÖÕîËÄ¸ÙÅ©ÕΩπ}ïπçΩëî°Ö¡¡}’…∞†úΩçΩµ¡Öπ‰ΩôïºΩ…ï≈’ïÕ—ÃΩµÖ¿µ¡±Öçïµïπ–ú§∞Å)M=9}U9MA}U9%=ÅÅ)M=9}U9MA}M1M!L§Ä¸¯∞(ÄÅçÕ…òËÄ¸ÙÅ©ÕΩπ}ïπçΩëî°çÕ…ôQΩ≠ï∏†§∞Å)M=9}U9MA}U9%=ÅÅ)M=9}U9MA}M1M!L§Ä¸¯)ÙÏ(ΩÕç…•¡–¯(ÒÕç…•¡–ÅÕ…åÙà¸ÙÅÖ¡¡}’…∞†úΩÖÕÕï—ÃΩ©ÃΩôïºµµÖ¿µ¡±Öçïµïπ–π©Ãú§Ä¸¯˝ÿÙ¸ÙÅô•±ïµ—•µî°âÖÕï}¡Ö—††ù¡’â±•åΩÖÕÕï—ÃΩ©ÃΩôïºµµÖ¿µ¡±Öçïµïπ–π©Ãú§§Ä¸¯à¯ΩÕç…•¡–¯(