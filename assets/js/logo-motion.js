/* Sahand Service — local-first logo motion editor and WebM renderer. */
(function () {
    'use strict';

    let WIDTH = 1920;
    let HEIGHT = 1080;
    const MAX_LOGO_BYTES = 8 * 1024 * 1024;
    const DEFAULT_LOGO = 'assets/images/defaults/logo.svg';
    const canvas = document.getElementById('motionCanvas');
    const ctx = canvas && canvas.getContext('2d', { alpha: false });
    if (!canvas || !ctx) return;

    const MOTION_GROUPS = [
        { family: 'orbit', category: 'مداری', names: ['مدار آبی', 'مدار طلایی', 'مدار دوقلو', 'مدار مورب', 'مدار ضربانی'], descriptions: ['حلقه‌های همگرا', 'قوس گرم و لوکس', 'دو مسیر چرخان', 'مدار با زاویه‌ی پویا', 'گردش همراه با نبض'] },
        { family: 'sweep', category: 'پرتو نور', names: ['پرتو افقی', 'پرتو معکوس', 'اسکن عمودی', 'برش قطری', 'لیزر طلایی'], descriptions: ['آشکارسازی از چپ', 'ورود از سمت مقابل', 'پرده‌ی عمودی نور', 'برش مورب سینمایی', 'خط نور گرم و درخشان'] },
        { family: 'pulse', category: 'هاله', names: ['درخشش آرام', 'هاله‌ی دوگانه', 'ضربان نوری', 'بلوم نئون', 'نبض شامپاینی'], descriptions: ['ظهور نرم با نور پخش', 'دو حلقه‌ی نورانی', 'تپش کنترل‌شده', 'هاله‌ی نئونی مدرن', 'درخشش گرم و ظریف'] },
        { family: 'spin', category: 'چرخش', names: ['چرخش نرم', 'چرخش ۹۰ درجه', 'گردش کامل', 'چرخش معکوس', 'چرخش سه‌بعدی'], descriptions: ['چرخش کوتاه و روان', 'ربع‌گردش فنری', 'یک دور کامل', 'گردش در خلاف جهت', 'ورود پرسپکتیو'] },
        { family: 'zoom', category: 'زوم و فوکوس', names: ['زوم سینمایی', 'زوم جهشی', 'ورود از دور', 'فوکوس آرام', 'زوم نقطه‌ای'], descriptions: ['نزدیک‌شدن با مکث', 'بزرگ‌نمایی فنری', 'ظهور از عمق صحنه', 'فوکوس تدریجی', 'ضربه‌ی ظریف دوربین'] },
        { family: 'rise', category: 'ورود و شناوری', names: ['طلوع عمودی', 'ورود از پایین', 'سقوط کنترل‌شده', 'شناور آرام', 'فنر لطیف'], descriptions: ['بالاآمدن از تاریکی', 'حرکت رو به بالا', 'فرود نرم و دقیق', 'شناوری سبک', 'جهش با فرود نرم'] },
        { family: 'scan', category: 'اسکن', names: ['اسکن افقی', 'اسکن عمودی', 'اسکن دوبل', 'نوارهای دیجیتال', 'اسکن متقاطع'], descriptions: ['روشن‌شدن خط‌به‌خط', 'پرده‌ی عمودی اسکن', 'دو موج اسکن', 'نمایش قطعه‌ای', 'دو پرتو متقاطع'] },
        { family: 'burst', category: 'ذرات و انرژی', names: ['فوران ذرات', 'انفجار ستاره‌ای', 'موج مداری', 'گردباد نور', 'شکوفایی'], descriptions: ['ذرات پیرامونی', 'جرقه‌های شعاعی', 'موج رو به بیرون', 'چرخش انرژی', 'بازشدن گلبرگ نور'] },
        { family: 'mask', category: 'ماسک و آشکارسازی', names: ['ماسک دایره‌ای', 'پرده از چپ', 'پرده از راست', 'ماسک قطری', 'آینه‌ی نرم'], descriptions: ['آشکارسازی شعاعی', 'بازشدن پرده‌ای', 'پرده‌ی معکوس', 'نمایش مورب لوگو', 'گشایش متقارن'] },
        { family: 'glitch', category: 'دیجیتال', names: ['گلیچ دیجیتال', 'کروم درخشان', 'هولوگرافیک', 'موج سیگنال', 'پایان باشکوه'], descriptions: ['اختلال دیجیتال کوتاه', 'هاله‌ی کرومی', 'رد نوری هولوگرافیک', 'تداخل موجی', 'ترکیب ذرات و مدار'] }
    ];
    const MOTION_STYLES = MOTION_GROUPS.flatMap(group => group.names.map((name, variant) => ({
        id: `${group.family}-${variant}`,
        family: group.family,
        category: group.category,
        variant,
        name,
        description: group.descriptions[variant]
    })));

    const BACKGROUND_GROUPS = [
        { family: 'ambient', category: 'هاله و نور', names: ['مه‌نور آهسته', 'هاله‌ی شناور', 'نبض آبی'] },
        { family: 'aurora', category: 'هاله و نور', names: ['شفق نرم', 'شفق عمودی', 'شفق دوگانه'] },
        { family: 'particles', category: 'ذرات', names: ['ذرات شناور', 'غبار طلایی', 'ستاره‌های دور'] },
        { family: 'grid', category: 'تکنولوژی', names: ['شبکه‌ی زنده', 'شبکه‌ی اسکن', 'مختصات متحرک'] },
        { family: 'rays', category: 'پرتو', names: ['پرتوهای چرخان', 'پرتو مورب', 'چرخ‌نور'] },
        { family: 'waves', category: 'موج', names: ['موج‌های نرم', 'موج افقی', 'موج دایره‌ای'] },
        { family: 'rain', category: 'ذرات', names: ['باران نور', 'بارش طلایی', 'رد ستاره'] },
        { family: 'orbits', category: 'مدار', names: ['حلقه‌های دور', 'مدارهای آرام', 'حلقه‌ی ضربان'] },
        { family: 'scan', category: 'تکنولوژی', names: ['اسکن سینمایی', 'پرتوی عمودی', 'خط داده'] },
        { family: 'galaxy', category: 'کیهانی', names: ['کهکشان کم‌نور', 'غبار کهکشانی', 'مارپیچ ستاره'] }
    ];
    const BACKGROUND_ANIMATIONS = BACKGROUND_GROUPS.flatMap(group => group.names.map((name, variant) => ({
        id: `${group.family}-${variant}`,
        family: group.family,
        category: group.category,
        variant,
        name
    })));

    const COLOR_PALETTES = [
        { name: 'آبی طلایی', accent: '#64c8ff', gold: '#f2c979' },
        { name: 'سبز نعنایی', accent: '#58dfbd', gold: '#b6f1d4' },
        { name: 'بنفش شامپاینی', accent: '#b39aff', gold: '#f1c980' },
        { name: 'یاقوتی', accent: '#ff668a', gold: '#ffc1a1' },
        { name: 'زمردی', accent: '#55d79c', gold: '#eac06b' },
        { name: 'غروب', accent: '#ff8e5e', gold: '#ffd16b' },
        { name: 'یخی', accent: '#82e8f4', gold: '#d9f6ff' },
        { name: 'اقیانوسی', accent: '#4ea9df', gold: '#f2aa64' },
        { name: 'نقره‌ای', accent: '#c9d5e8', gold: '#8cb8ec' },
        { name: 'رزگلد', accent: '#e69baa', gold: '#f5c59e' },
        { name: 'لاجوردی', accent: '#557dff', gold: '#f0d078' },
        { name: 'جنگلی', accent: '#76b98a', gold: '#d9c48d' },
        { name: 'لیمویی', accent: '#b9df53', gold: '#f4e8a0' },
        { name: 'اسطوخودوس', accent: '#9f8cff', gold: '#d6caff' },
        { name: 'مرجانی', accent: '#ff7668', gold: '#ffe0ad' },
        { name: 'فوشیا', accent: '#e865ce', gold: '#ffbcd2' },
        { name: 'فیروزه‌ای', accent: '#40d4c8', gold: '#ffe08a' },
        { name: 'برنزی', accent: '#cf9659', gold: '#f2cf9b' },
        { name: 'نئون', accent: '#75ffb1', gold: '#c9a5ff' },
        { name: 'آبی سلطنتی', accent: '#6094ff', gold: '#f2f2ff' }
    ];
    const BACKGROUND_COLORS = [
        { name: 'نیمه‌شب', value: '#07101f' },
        { name: 'ذغالی', value: '#101317' },
        { name: 'سرمه‌ای', value: '#101c38' },
        { name: 'بنفش تیره', value: '#171127' },
        { name: 'سبز جنگلی', value: '#0b211e' },
        { name: 'زرشکی تیره', value: '#24111b' },
        { name: 'آبی نفتی', value: '#092329' },
        { name: 'دودی', value: '#202732' }
    ];

    const canvasContext = ctx;
    const el = {
        brandName: document.getElementById('brandName'),
        tagline: document.getElementById('brandTagline'),
        phone: document.getElementById('brandPhone'),
        website: document.getElementById('brandWebsite'),
        brandEnglish: document.getElementById('brandEnglish'),
        customFontFiles: document.getElementById('customFontFiles'),
        addCustomFont: document.getElementById('addCustomFont'),
        customFontList: document.getElementById('customFontList'),
        fontLibraryStatus: document.getElementById('fontLibraryStatus'),
        textSettings: document.getElementById('textSettings'),
        saveProject: document.getElementById('saveProject'),
        loadProject: document.getElementById('loadProject'),
        projectFile: document.getElementById('projectFile'),
        previewGuides: document.getElementById('previewGuides'),
        safeGuides: document.getElementById('safeGuides'),
        audioFile: document.getElementById('audioFile'),
        voiceFile: document.getElementById('voiceFile'),
        audioUploadButton: document.getElementById('audioUploadButton'),
        voiceUploadButton: document.getElementById('voiceUploadButton'),
        timelineAddAudio: document.getElementById('timelineAddAudio'),
        timelineAddVoice: document.getElementById('timelineAddVoice'),
        timelineAddText: document.getElementById('timelineAddText'),
        timelineAddLogo: document.getElementById('timelineAddLogo'),
        removeAudio: document.getElementById('removeAudio'),
        audioTrackList: document.getElementById('audioTrackList'),
        selectedAudioControls: document.getElementById('selectedAudioControls'),
        addTextLayer: document.getElementById('addTextLayer'),
        addLogoLayer: document.getElementById('addLogoLayer'),
        logoLayerList: document.getElementById('logoLayerList'),
        audioFileName: document.getElementById('audioFileName'),
        audioFileMeta: document.getElementById('audioFileMeta'),
        audioPreview: document.getElementById('audioPreview'),
        audioMode: document.getElementById('audioMode'),
        audioVolume: document.getElementById('audioVolume'),
        audioVolumeValue: document.getElementById('audioVolumeValue'),
        audioStart: document.getElementById('audioStart'),
        audioStartValue: document.getElementById('audioStartValue'),
        audioFadeIn: document.getElementById('audioFadeIn'),
        audioFadeInValue: document.getElementById('audioFadeInValue'),
        audioFadeOut: document.getElementById('audioFadeOut'),
        audioFadeOutValue: document.getElementById('audioFadeOutValue'),
        timelineRuler: document.getElementById('timelineRuler'),
        timelineTracks: document.getElementById('timelineTracks'),
        keyframeTarget: document.getElementById('keyframeTarget'),
        keyframeProperty: document.getElementById('keyframeProperty'),
        keyframeEasing: document.getElementById('keyframeEasing'),
        keyframeValue: document.getElementById('keyframeValue'),
        keyframeValueName: document.getElementById('keyframeValueName'),
        keyframeValueLabel: document.getElementById('keyframeValueLabel'),
        keyframeTimeLabel: document.getElementById('keyframeTimeLabel'),
        keyframeCount: document.getElementById('keyframeCount'),
        keyframeList: document.getElementById('keyframeList'),
        addKeyframe: document.getElementById('addKeyframe'),
        deleteKeyframe: document.getElementById('deleteKeyframe'),
        file: document.getElementById('logoFile'),
        uploadZone: document.getElementById('uploadZone'),
        resetLogo: document.getElementById('resetLogo'),
        logoScale: document.getElementById('logoScale'),
        logoScaleValue: document.getElementById('logoScaleValue'),
        logoEasing: document.getElementById('logoEasing'),
        logoExitEffect: document.getElementById('logoExitEffect'),
        logoEntryTime: document.getElementById('logoEntryTime'),
        logoEntryTimeValue: document.getElementById('logoEntryTimeValue'),
        logoEntryDuration: document.getElementById('logoEntryDuration'),
        logoEntryDurationValue: document.getElementById('logoEntryDurationValue'),
        logoExitTime: document.getElementById('logoExitTime'),
        logoExitTimeValue: document.getElementById('logoExitTimeValue'),
        logoExitDuration: document.getElementById('logoExitDuration'),
        logoExitDurationValue: document.getElementById('logoExitDurationValue'),
        motionIntensity: document.getElementById('motionIntensity'),
        motionIntensityValue: document.getElementById('motionIntensityValue'),
        thumb: document.getElementById('logoThumb'),
        fileName: document.getElementById('logoFileName'),
        motionSearch: document.getElementById('motionSearch'),
        motionCategories: document.getElementById('motionCategories'),
        motionStyles: document.getElementById('motionStyles'),
        motionCount: document.getElementById('motionStyleCount'),
        selectedMotion: document.getElementById('selectedMotionName'),
        palette: document.getElementById('colorPalettes'),
        accentColor: document.getElementById('accentColor'),
        goldColor: document.getElementById('goldColor'),
        backgroundPalettes: document.getElementById('backgroundPalettes'),
        backgroundColor: document.getElementById('backgroundColor'),
        backgroundSearch: document.getElementById('backgroundSearch'),
        backgroundCategories: document.getElementById('backgroundCategories'),
        backgroundGrid: document.getElementById('backgroundAnimations'),
        backgroundCount: document.getElementById('backgroundAnimationCount'),
        backgroundIntensity: document.getElementById('backgroundIntensity'),
        backgroundIntensityValue: document.getElementById('backgroundIntensityValue'),
        backgroundSpeed: document.getElementById('backgroundSpeed'),
        backgroundSpeedValue: document.getElementById('backgroundSpeedValue'),
        duration: document.getElementById('durationSelect'),
        quality: document.getElementById('qualitySelect'),
        aspect: document.getElementById('aspectSelect'),
        fps: document.getElementById('fpsSelect'),
        codec: document.getElementById('codecSelect'),
        bitrate: document.getElementById('bitrateSelect'),
        format: document.getElementById('formatSelect'),
        specFormat: document.getElementById('specFormat'),
        outputSummary: document.getElementById('outputSummary'),
        stage: document.getElementById('stage'),
        aspectLabel: document.getElementById('aspectLabel'),
        play: document.getElementById('playBtn'),
        playIcon: document.getElementById('playIcon'),
        pauseIcon: document.getElementById('pauseIcon'),
        restart: document.getElementById('restartBtn'),
        range: document.getElementById('timelineRange'),
        fill: document.getElementById('timelineFill'),
        currentTime: document.getElementById('currentTime'),
        totalTime: document.getElementById('totalTime'),
        export: document.getElementById('exportVideo'),
        bundleLink: document.getElementById('downloadBundle'),
        exportLabel: document.querySelector('#exportVideo span'),
        frame: document.getElementById('downloadFrame'),
        resolution: document.getElementById('stageResolution'),
        specResolution: document.getElementById('specResolution'),
        specDuration: document.getElementById('specDuration'),
        specFps: document.getElementById('specFps'),
        overlay: document.getElementById('renderOverlay'),
        renderProgress: document.getElementById('renderProgress'),
        toast: document.getElementById('toast'),
        support: document.getElementById('supportNote'),
        videoResult: document.getElementById('videoResult'),
        videoResultMeta: document.getElementById('videoResultMeta'),
        videoDownloadLink: document.getElementById('videoDownloadLink'),
        videoResultClose: document.getElementById('videoResultClose')
    };

    const TEXT_ITEMS = [
        { key: 'title', label: 'نام برند', defaultY: 60.1, rtl: true, valueFrom: 'title', defaults: { fontFamily: 'Vazirmatn', fontSize: 68, fontWeight: '800', letterSpacing: 0, alignment: 'center', italic: false, xOffset: 0, yOffset: 0, maxWidth: 84, opacity: 100, autoColor: true, color: '#f4f7fd', strokeWidth: 0, strokeColor: '#07101f', shadowBlur: 18, shadowColor: '#07101f', enterEffect: 'rise', exitEffect: 'fade', easing: 'cinematic', entryTime: 2.2, entryDuration: .9, exitTime: 6.9, exitDuration: .7, exitAuto: true } },
        { key: 'tagline', label: 'شعار کوتاه', defaultY: 70.9, rtl: true, valueFrom: 'tagline', defaults: { fontFamily: 'Vazirmatn', fontSize: 30, fontWeight: '500', letterSpacing: 0, alignment: 'center', italic: false, xOffset: 0, yOffset: 0, maxWidth: 84, opacity: 100, autoColor: true, color: '#b9c7dc', strokeWidth: 0, strokeColor: '#07101f', shadowBlur: 4, shadowColor: '#07101f', enterEffect: 'rise', exitEffect: 'fade', easing: 'cinematic', entryTime: 2.38, entryDuration: .8, exitTime: 6.9, exitDuration: .7, exitAuto: true } },
        { key: 'website', label: 'وب‌سایت', defaultY: 79, rtl: false, valueFrom: 'website', defaults: { fontFamily: 'Vazirmatn', fontSize: 20, fontWeight: '600', letterSpacing: 0, alignment: 'left', italic: false, xOffset: 0, yOffset: 0, maxWidth: 38, opacity: 100, autoColor: true, color: '#d2def0', strokeWidth: 0, strokeColor: '#07101f', shadowBlur: 0, shadowColor: '#07101f', enterEffect: 'rise', exitEffect: 'fade', easing: 'cinematic', entryTime: 2.56, entryDuration: .8, exitTime: 7, exitDuration: .65, exitAuto: true } },
        { key: 'phone', label: 'شماره تلفن', defaultY: 79, rtl: false, valueFrom: 'phone', defaults: { fontFamily: 'Vazirmatn', fontSize: 20, fontWeight: '600', letterSpacing: 0, alignment: 'left', italic: false, xOffset: 0, yOffset: 0, maxWidth: 38, opacity: 100, autoColor: true, color: '#d2def0', strokeWidth: 0, strokeColor: '#07101f', shadowBlur: 0, shadowColor: '#07101f', enterEffect: 'rise', exitEffect: 'fade', easing: 'cinematic', entryTime: 2.56, entryDuration: .8, exitTime: 7, exitDuration: .65, exitAuto: true } },
        { key: 'english', label: 'نوشته‌ی انگلیسی پایانی', defaultY: 86.6, rtl: false, valueFrom: 'englishText', defaults: { fontFamily: 'Arial', fontSize: 18, fontWeight: '700', letterSpacing: 5, alignment: 'center', italic: false, xOffset: 0, yOffset: 0, maxWidth: 78, opacity: 100, autoColor: true, color: '#9db1cc', strokeWidth: 0, strokeColor: '#07101f', shadowBlur: 0, shadowColor: '#07101f', enterEffect: 'rise', exitEffect: 'fade', easing: 'cinematic', entryTime: 2.72, entryDuration: .8, exitTime: 7.15, exitDuration: .65, exitAuto: true, caseMode: 'upper' } }
    ];
    const TEXT_EFFECTS = [
        ['rise', 'بالاآمدن نرم'], ['fade', 'محو و ظاهر'], ['slide-left', 'ورود از راست'], ['slide-right', 'ورود از چپ'],
        ['zoom', 'زوم نرم'], ['type', 'تایپ تدریجی'], ['wipe', 'پرده‌ی نوری'], ['rotate', 'چرخش ظریف'], ['blur', 'فوکوس تدریجی']
    ];
    const TEXT_EXIT_EFFECTS = [
        ['fade', 'محو تدریجی'], ['none', 'بدون خروج'], ['descend', 'پایین‌رفتن نرم'], ['slide-left', 'خروج به چپ'],
        ['slide-right', 'خروج به راست'], ['zoom', 'کوچک‌شدن'], ['wipe', 'جمع‌شدن پرده'], ['rotate', 'چرخش خروج'], ['blur', 'محو در فوکوس']
    ];
    const EASING_OPTIONS = [['cinematic', 'سینمایی · نرم'], ['smooth', 'شتاب و کاهش نرم'], ['spring', 'فنری'], ['sharp', 'سریع و دقیق'], ['linear', 'خطی']];
    const state = {
        title: el.brandName.value.trim() || 'سهند سرویس',
        tagline: el.tagline.value.trim(),
        phone: el.phone.value.trim(),
        website: el.website.value.trim(),
        logoScale: Number(el.logoScale.value) / 100 || 1,
        englishText: el.brandEnglish.value.trim(),
        textStyles: Object.fromEntries(TEXT_ITEMS.map(item => [item.key, { ...item.defaults }])),
        extraTextLayers: [],
        textLayerSequence: 0,
        logoLayers: [],
        logoLayerSequence: 0,
        customFonts: [],
        keyframes: [],
        keyframeSequence: 0,
        selectedKeyframeId: null,
        audioFile: null,
        audioUrl: null,
        audioDuration: 0,
        audioStart: 0,
        audioPeaks: [],
        audioTracks: [],
        audioTrackSequence: 0,
        selectedAudioTrackId: null,
        audioPreviewAssigned: false,
        timelinePointerDrag: null,
        timelineRenderPending: false,
        audioVolume: 1,
        audioFadeIn: .5,
        audioFadeOut: 1,
        audioLoop: false,
        audioContext: null,
        audioSource: null,
        audioGain: null,
        audioDestination: null,
        audioSourceTrack: null,
        audioCaptureStream: null,
        audioLastSync: 0,
        audioLastTimelineTime: -1,
        logoEasing: el.logoEasing.value || 'cinematic',
        logoEntryTime: Number(el.logoEntryTime.value) || .3,
        logoEntryDuration: Number(el.logoEntryDuration.value) || 1.3,
        logoExitEffect: el.logoExitEffect.value || 'none',
        logoExitTime: Number(el.logoExitTime.value) || 7.2,
        logoExitDuration: Number(el.logoExitDuration.value) || .6,
        logoExitAuto: true,
        motionIntensity: Number(el.motionIntensity.value) / 100 || 1,
        motionStyle: MOTION_STYLES[0],
        motionCategory: 'همه',
        backgroundAnimation: BACKGROUND_ANIMATIONS[0],
        backgroundCategory: 'همه',
        accent: COLOR_PALETTES[0].accent,
        gold: COLOR_PALETTES[0].gold,
        background: BACKGROUND_COLORS[0].value,
        backgroundIntensity: 0.7,
        backgroundSpeed: 1,
        duration: Number(el.duration.value) || 8,
        resolution: Number(el.quality.value) || 1080,
        aspect: el.aspect.value || '16:9',
        fps: Number(el.fps.value) || 30,
        codec: el.codec.value || 'auto',
        format: el.format.value || 'webm',
        bitrate: el.bitrate.value || 'high',
        playing: true,
        offset: 0,
        startedAt: performance.now(),
        scrubbing: false,
        exporting: false,
        exportStart: 0,
        exportStopRequested: false,
        exportFrameIndex: 0,
        exportTotalFrames: 0,
        exportTimer: 0,
        exportMimeType: '',
        frameTrack: null,
        manualFrameCapture: false,
        restorePlaying: true,
        restoreOffset: 0,
        recorder: null,
        stream: null,
        chunks: [],
        fallbackTimer: 0,
        logo: null,
        logoLoadId: 0,
        objectUrl: null,
        lastVideoUrl: null,
        exportPreparing: false,
        exportOriginalLogo: null,
        exportLogoUrl: null,
        toastTimer: 0
    };

    const LOGO_KEYFRAME_PROPERTIES = [
        { key: 'scale', label: 'مقیاس لوگو', min: 50, max: 150, step: 1, unit: '٪' },
        { key: 'opacity', label: 'شفافیت', min: 0, max: 100, step: 1, unit: '٪' },
        { key: 'rotation', label: 'چرخش', min: -180, max: 180, step: 1, unit: '°' }
    ];
    const TEXT_KEYFRAME_PROPERTIES = [
        { key: 'opacity', label: 'شفافیت', min: 0, max: 100, step: 1, unit: '٪' },
        { key: 'fontSize', label: 'اندازه‌ی قلم', min: 10, max: 120, step: 1, unit: ' px' },
        { key: 'xOffset', label: 'جابجایی افقی', min: -30, max: 30, step: .5, unit: '٪' },
        { key: 'yOffset', label: 'جابجایی عمودی', min: -25, max: 25, step: .5, unit: '٪' },
        { key: 'letterSpacing', label: 'فاصله‌ی حروف', min: -2, max: 18, step: .5, unit: ' px' }
    ];
    const AUDIO_KEYFRAME_PROPERTIES = [{ key: 'volume', label: 'بلندی صدا', min: 0, max: 150, step: 1, unit: '٪' }];
    const LOGO_LAYER_KEYFRAME_PROPERTIES = [
        { key: 'scale', label: 'مقیاس کپی لوگو', min: 15, max: 100, step: 1, unit: '٪' },
        { key: 'opacity', label: 'شفافیت', min: 0, max: 100, step: 1, unit: '٪' },
        { key: 'rotation', label: 'چرخش', min: -180, max: 180, step: 1, unit: '°' },
        { key: 'xOffset', label: 'جابجایی افقی', min: -45, max: 45, step: .5, unit: '٪' },
        { key: 'yOffset', label: 'جابجایی عمودی', min: -35, max: 35, step: .5, unit: '٪' }
    ];
    const KEYFRAME_PROPERTIES = Object.fromEntries([
        ['logo', LOGO_KEYFRAME_PROPERTIES],
        ...TEXT_ITEMS.map(item => [item.key, TEXT_KEYFRAME_PROPERTIES])
    ]);

    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
    const easeOutCubic = value => 1 - Math.pow(1 - clamp(value, 0, 1), 3);
    const easeInOut = value => {
        const t = clamp(value, 0, 1);
        return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
    };
    const easeOutBack = value => {
        const t = clamp(value, 0, 1);
        const c1 = 1.70158;
        const c3 = c1 + 1;
        return 1 + c3 * Math.pow(t - 1, 3) + c1 * Math.pow(t - 1, 2);
    };

    function easeBySetting(value, easing) {
        const t = clamp(value, 0, 1);
        if (easing === 'linear') return t;
        if (easing === 'smooth') return easeInOut(t);
        if (easing === 'spring') return clamp(easeOutBack(t), 0, 1);
        if (easing === 'sharp') return 1 - Math.pow(1 - t, 4);
        return easeOutCubic(t);
    }

    function parseColor(hex) {
        const value = String(hex || '#64c8ff').replace('#', '');
        const full = value.length === 3 ? value.split('').map(part => part + part).join('') : value;
        const number = parseInt(full, 16);
        if (!Number.isFinite(number)) return [100, 200, 255];
        return [(number >> 16) & 255, (number >> 8) & 255, number & 255];
    }

    function rgba(hex, alpha) {
        const [r, g, b] = parseColor(hex);
        return `rgba(${r},${g},${b},${alpha})`;
    }

    function mixColor(hex, target, amount) {
        const a = parseColor(hex);
        const b = parseColor(target);
        const t = clamp(amount, 0, 1);
        const values = a.map((part, index) => Math.round(part + (b[index] - part) * t));
        return `#${values.map(part => part.toString(16).padStart(2, '0')).join('')}`;
    }

    function colorIsLight(hex) {
        const [r, g, b] = parseColor(hex).map(value => {
            const s = value / 255;
            return s <= .04045 ? s / 12.92 : Math.pow((s + .055) / 1.055, 2.4);
        });
        return .2126 * r + .7152 * g + .0722 * b > .37;
    }

    function roundRectPath(context, x, y, width, height, radius) {
        const r = Math.max(0, Math.min(radius, width / 2, height / 2));
        context.beginPath();
        context.moveTo(x + r, y);
        context.lineTo(x + width - r, y);
        context.arcTo(x + width, y, x + width, y + r, r);
        context.lineTo(x + width, y + height - r);
        context.arcTo(x + width, y + height, x + width - r, y + height, r);
        context.lineTo(x + r, y + height);
        context.arcTo(x, y + height, x, y + height - r, r);
        context.lineTo(x, y + r);
        context.arcTo(x, y, x + r, y, r);
        context.closePath();
    }

    function designSizeForAspect(aspect) {
        if (aspect === '9:16') return [1080, 1920];
        if (aspect === '1:1') return [1080, 1080];
        if (aspect === '4:5') return [1080, 1350];
        return [1920, 1080];
    }

    function outputSizeForSettings() {
        const res = state.resolution;
        if (state.aspect === '9:16') return [res, Math.round(res * 16 / 9)];
        if (state.aspect === '1:1') return [res, res];
        if (state.aspect === '4:5') return [res, Math.round(res * 5 / 4)];
        return [Math.round(res * 16 / 9), res];
    }

    function formatNumber(value) {
        return Number(value).toLocaleString('en-US');
    }

    function updateStageLayout() {
        const aspectParts = state.aspect.split(':').map(Number);
        const aspectRatio = aspectParts[0] / aspectParts[1];
        el.stage.style.aspectRatio = `${aspectParts[0]} / ${aspectParts[1]}`;
        el.aspectLabel.textContent = state.aspect;
        const availableWidth = el.stage.parentElement ? el.stage.parentElement.clientWidth : 900;
        if (aspectRatio < 1.62) {
            const maxHeight = Math.max(260, Math.min(760, window.innerHeight * .72));
            const width = Math.min(availableWidth, maxHeight * aspectRatio);
            el.stage.style.width = `${Math.floor(width)}px`;
            el.stage.style.marginInline = 'auto';
        } else {
            el.stage.style.width = '100%';
            el.stage.style.marginInline = '0';
        }
    }

    function outputDetails() {
        const [width, height] = outputSizeForSettings();
        return { width, height, label: `${formatNumber(width)} × ${formatNumber(height)}` };
    }

    function updateOutputSummary() {
        const details = outputDetails();
        const durationText = state.duration.toLocaleString('fa-IR');
        const formatName = state.format === 'mp4' ? 'MP4' : 'WebM';
        el.outputSummary.textContent = `${details.label} · ${state.fps}fps · ${durationText} ثانیه · ${formatName}`;
        el.specResolution.textContent = details.label;
        el.resolution.textContent = details.label;
        el.specDuration.textContent = `${durationText} ثانیه`;
        el.specFps.textContent = `${state.fps} fps`;
        el.specFormat.textContent = `${formatName} · بدون واترمارک`;
    }

    function updateCanvasResolution() {
        if (state.exporting) return;
        [WIDTH, HEIGHT] = designSizeForAspect(state.aspect);
        const details = outputDetails();
        if (canvas.width !== details.width || canvas.height !== details.height) {
            canvas.width = details.width;
            canvas.height = details.height;
        }
        updateOutputSummary();
        updateStageLayout();
    }

    function secondsLabel(value) {
        return `${Number(value).toLocaleString('fa-IR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} ثانیه`;
    }

    const FONT_PRESETS = [
        ['Vazirmatn', 'وزیرمتن'], ['Tahoma', 'Tahoma'], ['Arial', 'Arial'],
        ['Georgia', 'Georgia · Serif'], ['monospace', 'Monospace']
    ];
    const WEIGHT_OPTIONS = [['300', 'Light · 300'], ['400', 'معمولی · 400'], ['500', 'متوسط · 500'], ['600', 'نیمه‌ضخیم · 600'], ['700', 'ضخیم · 700'], ['800', 'خیلی ضخیم · 800'], ['900', 'سیاه · 900']];
    const ALIGN_OPTIONS = [['left', 'چپ'], ['center', 'وسط'], ['right', 'راست']];

    function addTextSelect(grid, key, property, labelText, options) {
        const label = document.createElement('label');
        label.className = 'lm-text-setting-control';
        const title = document.createElement('span');
        title.textContent = labelText;
        const select = document.createElement('select');
        select.className = 'lm-select';
        select.dataset.textKey = key;
        select.dataset.textSetting = property;
        select.id = `text-${key}-${property}`;
        options.forEach(([value, name]) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = name;
            select.appendChild(option);
        });
        label.append(title, select);
        grid.appendChild(label);
        return select;
    }

    function addTextRange(grid, key, property, labelText, min, max, step, unit) {
        const label = document.createElement('label');
        label.className = 'lm-text-setting-control';
        const head = document.createElement('span');
        head.className = 'lm-range-line';
        const title = document.createElement('span');
        title.textContent = labelText;
        const output = document.createElement('b');
        output.dataset.textValue = `${key}:${property}`;
        head.append(title, output);
        const input = document.createElement('input');
        input.type = 'range';
        input.min = String(min);
        input.max = property === 'entryTime' || property === 'exitTime' ? String(state.duration) : String(max);
        input.step = String(step);
        input.dataset.textKey = key;
        input.dataset.textSetting = property;
        input.dataset.unit = unit || '';
        label.append(head, input);
        grid.appendChild(label);
        return input;
    }

    function addTextColor(grid, key, property, labelText) {
        const label = document.createElement('label');
        label.className = 'lm-text-setting-control';
        const title = document.createElement('span');
        title.textContent = labelText;
        const input = document.createElement('input');
        input.type = 'color';
        input.dataset.textKey = key;
        input.dataset.textSetting = property;
        label.append(title, input);
        grid.appendChild(label);
        return input;
    }

    function addTextCheckbox(grid, key, property, labelText) {
        const label = document.createElement('label');
        label.className = 'lm-text-inline-check';
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.dataset.textKey = key;
        input.dataset.textSetting = property;
        const title = document.createElement('span');
        title.textContent = labelText;
        label.append(input, title);
        grid.appendChild(label);
        return input;
    }

    function addTextColorMode(grid, key) {
        const wrap = document.createElement('div');
        wrap.className = 'lm-text-setting-control';
        const title = document.createElement('span');
        title.textContent = 'رنگ متن';
        const row = document.createElement('div');
        row.className = 'lm-text-auto-color';
        const color = document.createElement('input');
        color.type = 'color';
        color.dataset.textKey = key;
        color.dataset.textSetting = 'color';
        const autoLabel = document.createElement('label');
        autoLabel.className = 'lm-text-inline-check';
        const auto = document.createElement('input');
        auto.type = 'checkbox';
        auto.dataset.textKey = key;
        auto.dataset.textSetting = 'autoColor';
        const autoText = document.createElement('span');
        autoText.textContent = 'خودکار';
        autoLabel.append(auto, autoText);
        row.append(color, autoLabel);
        wrap.append(title, row);
        grid.appendChild(wrap);
        return { color, auto };
    }

    function addTextSection(container, title) {
        const heading = document.createElement('p');
        heading.className = 'lm-text-time-heading';
        heading.textContent = title;
        container.appendChild(heading);
        const grid = document.createElement('div');
        grid.className = 'lm-text-setting-grid';
        container.appendChild(grid);
        return grid;
    }

    function allTextItems() {
        return [...TEXT_ITEMS, ...state.extraTextLayers];
    }

    function textValueForItem(item) {
        if (!item) return '';
        return item.valueFrom ? String(state[item.valueFrom] || '') : String(item.value || '');
    }

    function addTextLayer(sourceKey, isDuplicate) {
        if (state.extraTextLayers.length >= 24) {
            showToast('حداکثر ۲۴ لایه‌ی نوشته‌ی اضافه می‌توانید بسازید.', 'warning');
            return null;
        }
        const source = sourceKey ? allTextItems().find(item => item.key === sourceKey) : null;
        const templateKey = source ? (source.templateKey || source.key) : 'custom';
        const defaults = source ? (source.defaults || styleForText(source.key)) : TEXT_ITEMS[0].defaults;
        state.textLayerSequence += 1;
        const key = `text-layer-${Date.now().toString(36)}-${state.textLayerSequence.toString(36)}`;
        const siblingCount = state.extraTextLayers.filter(item => item.templateKey === templateKey).length;
        const sourceY = source ? Number(source.defaultY) : 52;
        const layer = {
            key,
            label: source ? `${source.label} · کپی ${siblingCount + 1}` : `نوشته‌ی جدید ${siblingCount + 1}`,
            templateKey,
            defaultY: source && ['phone', 'website'].includes(templateKey) ? sourceY : clamp(sourceY + (isDuplicate ? ((siblingCount % 2 ? -1 : 1) * 6) : 0), 8, 92),
            rtl: source ? !!source.rtl : true,
            icon: source && source.icon ? source.icon : (templateKey === 'phone' ? 'phone' : (templateKey === 'website' ? 'web' : '')),
            value: source ? textValueForItem(source) : 'نوشته‌ی جدید',
            defaults: { ...defaults }
        };
        state.extraTextLayers.push(layer);
        const style = { ...styleForText(source ? source.key : 'title') };
        if (isDuplicate && !['phone', 'website'].includes(templateKey)) {
            style.yOffset = clamp((Number(style.yOffset) || 0) + (siblingCount % 2 ? -4 : 4), -25, 25);
        }
        state.textStyles[key] = style;
        buildTextSettings();
        const addedDetails = el.textSettings.querySelector(`[data-text-layer-key="${key}"]`);
        if (addedDetails) { addedDetails.open = true; addedDetails.scrollIntoView({ block: 'nearest' }); }
        updateTextTimingControls(key);
        renderTimelineTracks();
        drawFrame(currentTime(performance.now()));
        showToast(source ? `لایه‌ی «${layer.label}» به‌صورت مستقل اضافه شد.` : 'لایه‌ی نوشته‌ی جدید اضافه شد.');
        return layer;
    }

    function removeTextLayer(key) {
        const layer = state.extraTextLayers.find(item => item.key === key);
        if (!layer) return;
        state.extraTextLayers = state.extraTextLayers.filter(item => item.key !== key);
        delete state.textStyles[key];
        state.keyframes = state.keyframes.filter(frame => frame.target !== key);
        if (state.selectedKeyframeId && !state.keyframes.some(frame => frame.id === state.selectedKeyframeId)) state.selectedKeyframeId = null;
        buildTextSettings();
        updateTextTimingControls();
        renderTimelineTracks();
        drawFrame(currentTime(performance.now()));
        showToast('لایه‌ی نوشته حذف شد.');
    }

    function buildTextSettings() {
        el.textSettings.replaceChildren();
        allTextItems().forEach((item, itemIndex) => {
            const details = document.createElement('details');
            details.dataset.textLayerKey = item.key;
            details.className = 'lm-text-settings' + (item.templateKey ? ' is-repeatable-layer' : '');
            if (itemIndex === 0) details.open = true;
            const summary = document.createElement('summary');
            summary.textContent = item.label;
            const body = document.createElement('div');
            body.className = 'lm-text-settings-body';
            const style = styleForText(item.key);

            if (item.templateKey) {
                const contentLabel = document.createElement('label');
                contentLabel.className = 'lm-field-label lm-layer-content-label';
                contentLabel.textContent = 'متن این لایه';
                const content = document.createElement('textarea');
                content.className = 'lm-input lm-textarea lm-layer-content';
                content.rows = 2;
                content.maxLength = 120;
                content.value = item.value || '';
                content.dataset.textKey = item.key;
                content.dataset.textSetting = 'content';
                content.setAttribute('aria-label', `محتوای ${item.label}`);
                body.append(contentLabel, content);
            }

            const layerActions = document.createElement('div');
            layerActions.className = 'lm-layer-actions';
            const duplicate = document.createElement('button');
            duplicate.type = 'button';
            duplicate.className = 'lm-layer-action';
            duplicate.textContent = '⧉ تکثیر این نوشته';
            duplicate.addEventListener('click', event => {
                event.preventDefault();
                addTextLayer(item.key, true);
            });
            layerActions.appendChild(duplicate);
            if (item.templateKey) {
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'lm-layer-action is-remove';
                remove.textContent = 'حذف لایه';
                remove.addEventListener('click', event => {
                    event.preventDefault();
                    removeTextLayer(item.key);
                });
                layerActions.appendChild(remove);
            }
            body.appendChild(layerActions);

            let grid = addTextSection(body, 'حروف و ظاهر');
            const fontOptions = [...FONT_PRESETS, ...state.customFonts.map(font => [font.family, `${font.name} · شخصی`])];
            addTextSelect(grid, item.key, 'fontFamily', 'فونت', fontOptions);
            addTextSelect(grid, item.key, 'fontWeight', 'ضخامت', WEIGHT_OPTIONS);
            addTextRange(grid, item.key, 'fontSize', 'اندازه', 10, 120, 1, 'px');
            addTextRange(grid, item.key, 'letterSpacing', 'فاصله‌ی حروف', -2, 18, .5, 'px');
            addTextSelect(grid, item.key, 'alignment', 'چیدمان افقی', ALIGN_OPTIONS);
            if (item.key === 'english' || item.templateKey === 'english') addTextSelect(grid, item.key, 'caseMode', 'حروف انگلیسی', [['upper', 'حروف بزرگ'], ['normal', 'بدون تغییر'], ['lower', 'حروف کوچک']]);
            addTextColorMode(grid, item.key);
            addTextCheckbox(grid, item.key, 'italic', 'حروف ایتالیک');

            grid = addTextSection(body, 'جایگاه و خوانایی');
            addTextRange(grid, item.key, 'xOffset', 'جابجایی افقی', -30, 30, 1, '%');
            addTextRange(grid, item.key, 'yOffset', 'جابجایی عمودی', -25, 25, 1, '%');
            addTextRange(grid, item.key, 'maxWidth', 'حداکثر عرض', 20, 96, 1, '%');
            addTextRange(grid, item.key, 'opacity', 'شفافیت', 10, 100, 1, '%');
            addTextRange(grid, item.key, 'strokeWidth', 'دورخط', 0, 8, .5, 'px');
            addTextColor(grid, item.key, 'strokeColor', 'رنگ دورخط');
            addTextRange(grid, item.key, 'shadowBlur', 'پخش سایه', 0, 40, 1, 'px');
            addTextColor(grid, item.key, 'shadowColor', 'رنگ سایه');

            grid = addTextSection(body, 'ترنزیشن مستقل');
            addTextSelect(grid, item.key, 'enterEffect', 'افکت ورود', TEXT_EFFECTS);
            addTextSelect(grid, item.key, 'exitEffect', 'افکت خروج', TEXT_EXIT_EFFECTS);
            addTextSelect(grid, item.key, 'easing', 'منحنی حرکت', EASING_OPTIONS);

            grid = addTextSection(body, 'زمان‌بندی ورود');
            addTextRange(grid, item.key, 'entryTime', 'شروع ورود', 0, state.duration, .1, 's');
            addTextRange(grid, item.key, 'entryDuration', 'مدت ورود', .2, 3, .1, 's');

            grid = addTextSection(body, 'زمان‌بندی خروج');
            addTextRange(grid, item.key, 'exitTime', 'شروع خروج', 0, state.duration, .1, 's');
            addTextRange(grid, item.key, 'exitDuration', 'مدت خروج', .2, 3, .1, 's');
            addTextCheckbox(grid, item.key, 'exitAuto', 'تنظیم خودکار نزدیک پایان کلیپ');

            details.append(summary, body);
            el.textSettings.appendChild(details);
        });
        syncTextSettingsControls();
    }

    function textValueLabel(property, value) {
        const number = Number(value);
        const formatted = Number.isFinite(number) ? number.toLocaleString('fa-IR', { maximumFractionDigits: 1 }) : String(value);
        if (['entryTime', 'exitTime', 'entryDuration', 'exitDuration'].includes(property)) return `${formatted} ثانیه`;
        if (['fontSize', 'letterSpacing', 'strokeWidth', 'shadowBlur'].includes(property)) return `${formatted}px`;
        if (['xOffset', 'yOffset', 'maxWidth', 'opacity'].includes(property)) return `${formatted}٪`;
        return formatted;
    }

    function syncTextSettingsControls(onlyKey) {
        const keys = onlyKey ? [onlyKey] : allTextItems().map(item => item.key);
        keys.forEach(key => {
            const style = styleForText(key);
            el.textSettings.querySelectorAll(`[data-text-key="${key}"][data-text-setting]`).forEach(control => {
                const property = control.dataset.textSetting;
                if (property === 'content') {
                    const item = allTextItems().find(entry => entry.key === key);
                    control.value = item ? item.value : '';
                    return;
                }
                if (control.type === 'checkbox') control.checked = !!style[property];
                else if (style[property] !== undefined) control.value = String(style[property]);
                if (property === 'entryTime' || property === 'exitTime') control.max = String(state.duration);
                if (property === 'color') control.disabled = !!style.autoColor;
                if (control.type === 'range') {
                    const output = el.textSettings.querySelector(`[data-text-value="${key}:${property}"]`);
                    if (output) output.textContent = textValueLabel(property, style[property]);
                }
            });
            const colorInput = el.textSettings.querySelector(`[data-text-key="${key}"][data-text-setting="color"]`);
            if (colorInput) colorInput.value = style.color;
        });
    }

    function updateTextTimingControls(onlyKey) {
        const items = onlyKey ? allTextItems().filter(item => item.key === onlyKey) : allTextItems();
        items.forEach(item => {
            const style = styleForText(item.key);
            style.entryTime = clamp(Number(style.entryTime) || 0, 0, state.duration);
            style.entryDuration = clamp(Number(style.entryDuration) || .8, .2, Math.min(3, state.duration));
            if (style.exitAuto) {
                const exitLead = ({ title: 1.1, tagline: 1, website: .8, phone: .95, english: .85 })[item.templateKey || item.key] || 1;
                style.exitTime = Math.min(state.duration, Math.max(style.entryTime + style.entryDuration + .25, state.duration - exitLead));
            }
            style.exitTime = clamp(Number(style.exitTime) || 0, 0, state.duration);
            style.exitDuration = clamp(Number(style.exitDuration) || .6, .2, Math.min(3, state.duration));
        });
        syncTextSettingsControls(onlyKey);
        renderTimelineTracks();
    }

    function updateTextSettingFromControl(control) {
        const key = control.dataset.textKey;
        const property = control.dataset.textSetting;
        const style = state.textStyles[key];
        if (!property) return;
        if (property === 'content') {
            const item = state.extraTextLayers.find(entry => entry.key === key);
            if (!item) return;
            item.value = control.value.slice(0, 120);
            drawFrame(currentTime(performance.now()));
            return;
        }
        if (!style) return;
        if (control.type === 'checkbox') style[property] = control.checked;
        else if (control.type === 'range') style[property] = Number(control.value);
        else style[property] = control.value;
        if (property === 'exitTime') {
            style.exitAuto = false;
            const auto = el.textSettings.querySelector(`[data-text-key="${key}"][data-text-setting="exitAuto"]`);
            if (auto) auto.checked = false;
        }
        if (property === 'entryTime' || property === 'entryDuration' || property === 'exitAuto') {
            updateTextTimingControls(key);
        } else {
            syncTextSettingsControls(key);
        }
    }

    function addLogoLayer(sourceId) {
        if (state.logoLayers.length >= 8) {
            showToast('حداکثر ۸ کپی لوگو می‌توانید به صحنه اضافه کنید.', 'warning');
            return null;
        }
        state.logoLayerSequence += 1;
        const index = state.logoLayers.length;
        const source = state.logoLayers.find(item => item.id === sourceId);
        const layer = source ? {
            ...source,
            id: `logo-layer-${Date.now().toString(36)}-${state.logoLayerSequence.toString(36)}`,
            label: `${source.label || 'کپی لوگو'} · کپی ${index + 1}`,
            xOffset: clamp((Number(source.xOffset) || 0) + (index % 2 ? -6 : 6), -45, 45)
        } : {
            id: `logo-layer-${Date.now().toString(36)}-${state.logoLayerSequence.toString(36)}`,
            label: `کپی لوگو ${index + 1}`,
            scale: 42,
            xOffset: index % 2 ? 25 : -25,
            yOffset: 0,
            opacity: 100,
            entryTime: Math.min(state.duration, .5 + index * .15),
            entryDuration: .7,
            exitTime: Math.max(.5, state.duration - .8),
            exitDuration: .55,
            exitAuto: true
        };
        state.logoLayers.push(layer);
        renderLogoLayerList();
        const addedLogoLayer = el.logoLayerList.querySelector(`[data-logo-layer-id="${layer.id}"]`);
        if (addedLogoLayer) { addedLogoLayer.open = true; addedLogoLayer.scrollIntoView({ block: 'nearest' }); }
        renderTimelineTracks();
        drawFrame(currentTime(performance.now()));
        showToast('کپی لوگو با اندازه و زمان‌بندی مستقل اضافه شد.');
        return layer;
    }

    function removeLogoLayer(id) {
        state.logoLayers = state.logoLayers.filter(layer => layer.id !== id);
        state.keyframes = state.keyframes.filter(frame => frame.target !== `logoLayer:${id}`);
        if (state.selectedKeyframeId && !state.keyframes.some(frame => frame.id === state.selectedKeyframeId)) state.selectedKeyframeId = null;
        renderLogoLayerList();
        renderTimelineTracks();
        drawFrame(currentTime(performance.now()));
        showToast('لایه‌ی لوگو حذف شد.');
    }

    function renderLogoLayerList() {
        if (!el.logoLayerList) return;
        el.logoLayerList.replaceChildren();
        if (!state.logoLayers.length) {
            const empty = document.createElement('p');
            empty.className = 'lm-layer-empty-note';
            empty.textContent = 'هنوز کپی اضافه‌ای ندارید؛ با دکمه‌ی بالا یک لوگوی مستقل بسازید.';
            el.logoLayerList.appendChild(empty);
            return;
        }
        state.logoLayers.forEach((layer, index) => {
            const card = document.createElement('details');
            card.dataset.logoLayerId = layer.id;
            card.className = 'lm-logo-layer';
            const summary = document.createElement('summary');
            summary.textContent = layer.label || `کپی لوگو ${index + 1}`;
            const body = document.createElement('div');
            body.className = 'lm-logo-layer-body';
            const actions = document.createElement('div');
            actions.className = 'lm-layer-actions';
            const copy = document.createElement('button');
            copy.type = 'button';
            copy.className = 'lm-layer-action';
            copy.textContent = '⧉ تکثیر لوگو';
            copy.addEventListener('click', event => {
                event.preventDefault();
                addLogoLayer(layer.id);
            });
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'lm-layer-action is-remove';
            remove.textContent = 'حذف لایه';
            remove.addEventListener('click', event => {
                event.preventDefault();
                removeLogoLayer(layer.id);
            });
            actions.append(copy, remove);
            body.appendChild(actions);
            const grid = document.createElement('div');
            grid.className = 'lm-logo-layer-grid';
            const controls = [
                ['scale', 'اندازه', 15, 100, 1, '%'],
                ['xOffset', 'جابجایی افقی', -45, 45, 1, '%'],
                ['yOffset', 'جابجایی عمودی', -35, 35, 1, '%'],
                ['opacity', 'شفافیت', 0, 100, 1, '%'],
                ['entryTime', 'شروع ورود', 0, state.duration, .1, 's'],
                ['entryDuration', 'مدت ورود', .2, 3, .1, 's'],
                ['exitTime', 'شروع خروج', 0, state.duration, .1, 's'],
                ['exitDuration', 'مدت خروج', .2, 3, .1, 's']
            ];
            controls.forEach(([property, label, min, max, step, unit]) => {
                const wrap = document.createElement('label');
                wrap.className = 'lm-range-control';
                const caption = document.createElement('span');
                caption.className = 'lm-logo-layer-range-label';
                const title = document.createElement('span');
                title.textContent = label;
                const output = document.createElement('b');
                output.textContent = `${Number(layer[property]).toLocaleString('fa-IR', { maximumFractionDigits: 1 })}${unit}`;
                caption.append(title, output);
                const input = document.createElement('input');
                input.type = 'range';
                input.min = String(min);
                input.max = String(max);
                input.step = String(step);
                input.value = String(layer[property]);
                input.dataset.logoLayerId = layer.id;
                input.dataset.logoLayerSetting = property;
                wrap.append(caption, input);
                grid.appendChild(wrap);
            });
            const autoLabel = document.createElement('label');
            autoLabel.className = 'lm-text-inline-check lm-logo-layer-auto';
            const auto = document.createElement('input');
            auto.type = 'checkbox';
            auto.checked = !!layer.exitAuto;
            auto.dataset.logoLayerId = layer.id;
            auto.dataset.logoLayerSetting = 'exitAuto';
            const autoText = document.createElement('span');
            autoText.textContent = 'خروج خودکار نزدیک پایان کلیپ';
            autoLabel.append(auto, autoText);
            body.append(grid, autoLabel);
            card.append(summary, body);
            el.logoLayerList.appendChild(card);
        });
    }

    function updateLogoLayerFromControl(control) {
        const layer = state.logoLayers.find(item => item.id === control.dataset.logoLayerId);
        const property = control.dataset.logoLayerSetting;
        if (!layer || !property) return;
        layer[property] = control.type === 'checkbox' ? control.checked : Number(control.value);
        if (property === 'exitTime') {
            layer.exitAuto = false;
            const auto = el.logoLayerList.querySelector(`[data-logo-layer-id="${layer.id}"][data-logo-layer-setting="exitAuto"]`);
            if (auto) auto.checked = false;
        }
        if (property === 'entryTime' || property === 'entryDuration' || property === 'exitAuto') {
            layer.entryTime = clamp(Number(layer.entryTime) || 0, 0, state.duration);
            layer.entryDuration = clamp(Number(layer.entryDuration) || .7, .2, Math.min(3, state.duration));
            if (layer.exitAuto) layer.exitTime = Math.min(state.duration, Math.max(layer.entryTime + layer.entryDuration + .3, state.duration - .8));
            layer.exitTime = clamp(Number(layer.exitTime) || 0, 0, state.duration);
            layer.exitDuration = clamp(Number(layer.exitDuration) || .55, .2, Math.min(3, state.duration));
            const exitInput = el.logoLayerList.querySelector(`[data-logo-layer-id="${layer.id}"][data-logo-layer-setting="exitTime"]`);
            const exitAutoInput = el.logoLayerList.querySelector(`[data-logo-layer-id="${layer.id}"][data-logo-layer-setting="exitAuto"]`);
            if (exitInput) exitInput.value = String(layer.exitTime);
            if (exitAutoInput) exitAutoInput.checked = !!layer.exitAuto;
            const exitOutput = exitInput && exitInput.closest('.lm-range-control') && exitInput.closest('.lm-range-control').querySelector('b');
            if (exitOutput) exitOutput.textContent = `${Number(layer.exitTime).toLocaleString('fa-IR', { maximumFractionDigits: 1 })}s`;
        }
        const wrap = control.closest('.lm-range-control');
        const output = wrap && wrap.querySelector('b');
        if (output && control.type !== 'checkbox') {
            const unit = property === 'scale' || property === 'opacity' || property.endsWith('Offset') ? '٪' : ' ثانیه';
            output.textContent = `${Number(layer[property]).toLocaleString('fa-IR', { maximumFractionDigits: 1 })}${unit}`;
        }
        renderTimelineTracks();
        drawFrame(currentTime(performance.now()));
    }

    function updateLogoTimingControls() {
        el.logoEntryTime.max = String(state.duration);
        el.logoExitTime.max = String(state.duration);
        state.logoEntryTime = clamp(state.logoEntryTime, 0, state.duration);
        state.logoEntryDuration = clamp(state.logoEntryDuration, .4, Math.min(2.6, state.duration));
        if (state.logoExitAuto) {
            state.logoExitTime = Math.min(state.duration, Math.max(state.logoEntryTime + state.logoEntryDuration + .45, state.duration - .8));
        }
        state.logoExitTime = clamp(state.logoExitTime, 0, state.duration);
        state.logoExitDuration = clamp(state.logoExitDuration, .2, Math.min(2, state.duration));
        el.logoEntryTime.value = state.logoEntryTime.toFixed(1);
        el.logoEntryDuration.value = state.logoEntryDuration.toFixed(1);
        el.logoExitTime.value = state.logoExitTime.toFixed(1);
        el.logoExitDuration.value = state.logoExitDuration.toFixed(1);
        el.logoEntryTimeValue.textContent = secondsLabel(state.logoEntryTime);
        el.logoEntryDurationValue.textContent = secondsLabel(state.logoEntryDuration);
        el.logoExitTimeValue.textContent = secondsLabel(state.logoExitTime);
        el.logoExitDurationValue.textContent = secondsLabel(state.logoExitDuration);
        renderTimelineTracks();
    }

    function setDuration(value) {
        state.duration = clamp(Number(value) || 8, 1, 30);
        state.offset = 0;
        state.startedAt = performance.now();
        state.audioTracks.forEach(track => { track.start = clamp(Number(track.start) || 0, 0, state.duration); });
        state.logoLayers.forEach(layer => {
            layer.entryTime = clamp(Number(layer.entryTime) || 0, 0, state.duration);
            layer.entryDuration = clamp(Number(layer.entryDuration) || .7, .2, Math.min(3, state.duration));
            if (layer.exitAuto) layer.exitTime = Math.min(state.duration, Math.max(layer.entryTime + layer.entryDuration + .3, state.duration - .8));
            layer.exitTime = clamp(Number(layer.exitTime) || 0, 0, state.duration);
            layer.exitDuration = clamp(Number(layer.exitDuration) || .55, .2, Math.min(3, state.duration));
        });
        updateOutputSummary();
        updateTextTimingControls();
        updateLogoTimingControls();
        updateAudioControls();
        renderLogoLayerList();
        renderAudioTrackList();
        renderTimelineTracksForDurationChange();
        updateTransport(0);
    }

    function drawBackground(progress, seconds) {
        const intensity = state.backgroundIntensity;
        const light = colorIsLight(state.background);
        const baseA = mixColor(state.background, light ? '#ffffff' : '#1c2a43', light ? .28 : .34);
        const baseB = mixColor(state.background, light ? '#dce6f3' : '#020711', light ? .3 : .48);
        const bg = canvasContext.createLinearGradient(0, 0, WIDTH, HEIGHT);
        bg.addColorStop(0, baseA);
        bg.addColorStop(.5, state.background);
        bg.addColorStop(1, baseB);
        canvasContext.fillStyle = bg;
        canvasContext.fillRect(0, 0, WIDTH, HEIGHT);

        const speed = state.backgroundSpeed;
        const drift = Math.sin(seconds * .52 * speed) * WIDTH * .035;
        let glow = canvasContext.createRadialGradient(WIDTH * .5 + drift, HEIGHT * .39, 20, WIDTH * .5 + drift, HEIGHT * .39, Math.max(WIDTH, HEIGHT) * .46);
        glow.addColorStop(0, rgba(state.accent, (light ? .085 : .17) * intensity));
        glow.addColorStop(.42, rgba(state.accent, (light ? .045 : .075) * intensity));
        glow.addColorStop(1, rgba(state.accent, 0));
        canvasContext.fillStyle = glow;
        canvasContext.fillRect(0, 0, WIDTH, HEIGHT);

        glow = canvasContext.createRadialGradient(WIDTH * .75, HEIGHT * .78, 0, WIDTH * .75, HEIGHT * .78, Math.max(WIDTH, HEIGHT) * .38);
        glow.addColorStop(0, rgba(state.gold, (light ? .04 : .075) * intensity));
        glow.addColorStop(1, rgba(state.gold, 0));
        canvasContext.fillStyle = glow;
        canvasContext.fillRect(0, 0, WIDTH, HEIGHT);

        const gridColor = light ? 'rgba(53,77,112,.055)' : 'rgba(160,188,227,.045)';
        const gridX = Math.max(56, Math.round(WIDTH / 23));
        const gridY = Math.max(56, Math.round(HEIGHT / 13));
        canvasContext.save();
        canvasContext.strokeStyle = gridColor;
        canvasContext.lineWidth = 1;
        canvasContext.beginPath();
        for (let x = gridX; x < WIDTH; x += gridX) {
            canvasContext.moveTo(x, 24);
            canvasContext.lineTo(x, HEIGHT - 24);
        }
        for (let y = gridY; y < HEIGHT; y += gridY) {
            canvasContext.moveTo(24, y);
            canvasContext.lineTo(WIDTH - 24, y);
        }
        canvasContext.stroke();
        canvasContext.restore();

        drawBackgroundAnimation(progress, seconds, light);

        const streakX = -WIDTH * .2 + ((progress * 1.18) % 1.38) * WIDTH * 1.4;
        const streak = canvasContext.createLinearGradient(streakX - WIDTH * .13, 0, streakX + WIDTH * .13, 0);
        streak.addColorStop(0, 'rgba(255,255,255,0)');
        streak.addColorStop(.5, rgba(state.accent, (light ? .025 : .055) * intensity));
        streak.addColorStop(1, 'rgba(255,255,255,0)');
        canvasContext.fillStyle = streak;
        canvasContext.fillRect(0, HEIGHT * .68, WIDTH, 2);

        canvasContext.save();
        roundRectPath(canvasContext, 34, 34, WIDTH - 68, HEIGHT - 68, Math.min(31, WIDTH * .04));
        canvasContext.strokeStyle = light ? 'rgba(38,57,86,.13)' : 'rgba(197,216,246,.095)';
        canvasContext.lineWidth = 1.5;
        canvasContext.stroke();
        canvasContext.strokeStyle = rgba(state.accent, (light ? .3 : .38) * intensity);
        canvasContext.lineWidth = 2;
        const corner = Math.min(45, WIDTH * .06);
        const inset = 34;
        const edge = WIDTH - inset;
        const bottom = HEIGHT - inset;
        [[inset, inset, 1, 1], [edge, inset, -1, 1], [inset, bottom, 1, -1], [edge, bottom, -1, -1]].forEach(([x, y, dx, dy]) => {
            canvasContext.beginPath();
            canvasContext.moveTo(x + dx * corner, y);
            canvasContext.lineTo(x, y);
            canvasContext.lineTo(x, y + dy * corner);
            canvasContext.stroke();
        });
        canvasContext.restore();

        const stars = [
            [.145, .265, 2.2, .2], [.786, .222, 1.7, 1.8], [.773, .619, 2.4, .8],
            [.216, .679, 1.8, 2.1], [.624, .154, 1.5, .3], [.396, .8, 1.7, 1.2],
            [.866, .404, 1.8, 2.8], [.281, .425, 1.4, 1.1], [.705, .824, 1.7, 2.4]
        ];
        stars.forEach(([rx, ry, radius, phase]) => {
            const alpha = intensity * (.12 + (Math.sin(seconds * 1.35 * speed + phase) + 1) * .105);
            canvasContext.beginPath();
            canvasContext.arc(rx * WIDTH, ry * HEIGHT, radius, 0, Math.PI * 2);
            canvasContext.fillStyle = rgba(light ? '#35537d' : state.accent, alpha);
            canvasContext.fill();
        });
    }

    function deterministic(value) {
        const x = Math.sin(value * 127.1 + 311.7) * 43758.5453;
        return x - Math.floor(x);
    }

    function drawBackgroundAnimation(progress, seconds, light) {
        const anim = state.backgroundAnimation;
        if (!anim) return;
        const family = anim.family;
        const variant = anim.variant;
        const motion = seconds * state.backgroundSpeed * (.65 + variant * .18);
        const strength = state.backgroundIntensity * (light ? .58 : 1);
        const cx = WIDTH / 2;
        const cy = HEIGHT * .42;
        const faintColor = light ? '#3e5b82' : state.accent;

        if (family === 'ambient') {
            const centers = [
                [WIDTH * (.26 + .035 * Math.sin(motion * .55)), HEIGHT * .3],
                [WIDTH * (.72 + .045 * Math.sin(motion * .36 + 1)), HEIGHT * .67]
            ];
            centers.forEach(([x, y], index) => {
                const radius = Math.max(WIDTH, HEIGHT) * (.28 + variant * .018);
                const g = canvasContext.createRadialGradient(x, y, 0, x, y, radius);
                g.addColorStop(0, rgba(index ? state.gold : state.accent, .085 * strength));
                g.addColorStop(1, rgba(index ? state.gold : state.accent, 0));
                canvasContext.fillStyle = g;
                canvasContext.fillRect(0, 0, WIDTH, HEIGHT);
            });
        } else if (family === 'aurora') {
            canvasContext.save();
            for (let i = 0; i < 3; i++) {
                const y = HEIGHT * (.24 + i * .19) + Math.sin(motion * .5 + i) * HEIGHT * .055;
                const x = WIDTH * (.42 + Math.sin(motion * .2 + i * 1.8) * .24);
                const g = canvasContext.createRadialGradient(x, y, 0, x, y, Math.max(WIDTH, HEIGHT) * (.34 + variant * .025));
                g.addColorStop(0, rgba(i === 1 ? state.gold : state.accent, .09 * strength));
                g.addColorStop(1, rgba(state.accent, 0));
                canvasContext.fillStyle = g;
                canvasContext.fillRect(0, 0, WIDTH, HEIGHT);
            }
            canvasContext.restore();
        } else if (family === 'particles') {
            const count = 28 + variant * 12;
            canvasContext.save();
            for (let i = 0; i < count; i++) {
                const x = (deterministic(i + 2) * WIDTH + Math.sin(motion * (.12 + (i % 4) * .03) + i) * WIDTH * .025 + WIDTH) % WIDTH;
                const y = (deterministic(i + 41) * HEIGHT + seconds * state.backgroundSpeed * (8 + (i % 7) * 5) * (i % 2 ? 1 : -1) + HEIGHT * 3) % HEIGHT;
                const size = 1 + deterministic(i + 110) * (1.6 + variant * .4);
                canvasContext.globalAlpha = strength * (.1 + deterministic(i + 8) * .3);
                canvasContext.fillStyle = i % 5 === 0 ? state.gold : faintColor;
                canvasContext.shadowColor = canvasContext.fillStyle;
                canvasContext.shadowBlur = size * 4;
                canvasContext.beginPath();
                canvasContext.arc(x, y, size, 0, Math.PI * 2);
                canvasContext.fill();
            }
            canvasContext.restore();
        } else if (family === 'grid') {
            const x = ((motion * 35) % WIDTH);
            const y = ((motion * 21) % HEIGHT);
            canvasContext.save();
            const color = rgba(state.accent, .14 * strength);
            const gradX = canvasContext.createLinearGradient(x - 65, 0, x + 65, 0);
            gradX.addColorStop(0, rgba(state.accent, 0));
            gradX.addColorStop(.5, color);
            gradX.addColorStop(1, rgba(state.accent, 0));
            canvasContext.fillStyle = gradX;
            canvasContext.fillRect(x - 65, 0, 130, HEIGHT);
            if (variant > 0) {
                canvasContext.fillStyle = rgba(state.gold, .1 * strength);
                canvasContext.fillRect(0, y, WIDTH, 1.5 + variant * .4);
            }
            canvasContext.restore();
        } else if (family === 'rays') {
            canvasContext.save();
            const rayCount = 12 + variant * 5;
            const reach = Math.max(WIDTH, HEIGHT) * .72;
            for (let i = 0; i < rayCount; i++) {
                const angle = i / rayCount * Math.PI * 2 + motion * .1;
                const x2 = cx + Math.cos(angle) * reach;
                const y2 = cy + Math.sin(angle) * reach;
                const g = canvasContext.createLinearGradient(cx, cy, x2, y2);
                g.addColorStop(0, rgba(state.gold, .075 * strength));
                g.addColorStop(1, rgba(state.accent, 0));
                canvasContext.strokeStyle = g;
                canvasContext.lineWidth = 1 + (i % 3 === 0 ? 1 : 0);
                canvasContext.beginPath();
                canvasContext.moveTo(cx, cy);
                canvasContext.lineTo(x2, y2);
                canvasContext.stroke();
            }
            canvasContext.restore();
        } else if (family === 'waves') {
            canvasContext.save();
            canvasContext.lineWidth = 1.4;
            for (let line = 0; line < 4 + variant; line++) {
                const yBase = HEIGHT * (.5 + line * .105);
                canvasContext.beginPath();
                for (let x = 0; x <= WIDTH; x += Math.max(14, WIDTH / 90)) {
                    const y = yBase + Math.sin(x * (.006 + variant * .0007) + motion * .7 + line * 1.6) * (12 + variant * 4);
                    if (x === 0) canvasContext.moveTo(x, y); else canvasContext.lineTo(x, y);
                }
                canvasContext.strokeStyle = rgba(line % 3 === 0 ? state.gold : faintColor, .09 * strength);
                canvasContext.stroke();
            }
            canvasContext.restore();
        } else if (family === 'rain') {
            canvasContext.save();
            const count = 25 + variant * 12;
            for (let i = 0; i < count; i++) {
                const x = deterministic(i + 12) * WIDTH;
                const trail = 22 + deterministic(i + 33) * (45 + variant * 18);
                const y = (deterministic(i + 69) * HEIGHT + motion * (30 + deterministic(i + 1) * 24)) % (HEIGHT + trail) - trail;
                canvasContext.strokeStyle = rgba(i % 6 === 0 ? state.gold : faintColor, .14 * strength);
                canvasContext.lineWidth = 1 + (i % 5 === 0 ? .7 : 0);
                canvasContext.beginPath();
                canvasContext.moveTo(x, y);
                canvasContext.lineTo(x + 3 + variant, y + trail);
                canvasContext.stroke();
            }
            canvasContext.restore();
        } else if (family === 'orbits') {
            canvasContext.save();
            canvasContext.globalAlpha = .44 * strength;
            for (let i = 0; i < 5 + variant; i++) {
                const rx = Math.min(WIDTH * .47, 300 + i * Math.min(48, WIDTH * .042));
                const ry = Math.min(HEIGHT * .34, rx * (.28 + variant * .02));
                canvasContext.beginPath();
                canvasContext.ellipse(cx, cy, rx, ry, Math.sin(motion * .07 + i) * .12, 0, Math.PI * 2);
                canvasContext.setLineDash(i % 2 ? [2, 17] : [14, 30]);
                canvasContext.lineDashOffset = -motion * (5 + i * 1.4);
                canvasContext.strokeStyle = rgba(i % 3 === 0 ? state.gold : faintColor, .38);
                canvasContext.lineWidth = 1.2;
                canvasContext.stroke();
            }
            canvasContext.setLineDash([]);
            canvasContext.restore();
        } else if (family === 'scan') {
            const vertical = variant === 1;
            const position = (motion * (vertical ? HEIGHT : WIDTH) * .08) % (vertical ? HEIGHT : WIDTH);
            const start = vertical ? position - HEIGHT * .13 : position - WIDTH * .13;
            const end = vertical ? position + HEIGHT * .13 : position + WIDTH * .13;
            const gradient = vertical ? canvasContext.createLinearGradient(0, start, 0, end) : canvasContext.createLinearGradient(start, 0, end, 0);
            gradient.addColorStop(0, rgba(state.accent, 0));
            gradient.addColorStop(.5, rgba(state.accent, .12 * strength));
            gradient.addColorStop(1, rgba(state.accent, 0));
            canvasContext.fillStyle = gradient;
            if (vertical) canvasContext.fillRect(0, start, WIDTH, end - start); else canvasContext.fillRect(start, 0, end - start, HEIGHT);
            if (variant === 2) {
                canvasContext.strokeStyle = rgba(state.gold, .12 * strength);
                canvasContext.beginPath();
                canvasContext.moveTo(0, HEIGHT * .22 + Math.sin(motion) * 20);
                canvasContext.lineTo(WIDTH, HEIGHT * .22 + Math.sin(motion) * 20);
                canvasContext.stroke();
            }
        } else if (family === 'galaxy') {
            canvasContext.save();
            const count = 50 + variant * 30;
            for (let i = 0; i < count; i++) {
                const angle = deterministic(i + 2) * Math.PI * 2 + motion * (.035 + variant * .012);
                const radius = Math.sqrt(deterministic(i + 55)) * Math.min(WIDTH, HEIGHT) * (.44 + variant * .025);
                const x = cx + Math.cos(angle + radius * .0012) * radius;
                const y = cy + Math.sin(angle + radius * .0012) * radius * .7;
                const twinkle = .12 + (Math.sin(seconds * (1 + variant * .3) + i) + 1) * .14;
                canvasContext.globalAlpha = twinkle * strength;
                canvasContext.fillStyle = i % 9 === 0 ? state.gold : faintColor;
                canvasContext.beginPath();
                canvasContext.arc(x, y, .7 + deterministic(i + 1) * 1.5, 0, Math.PI * 2);
                canvasContext.fill();
            }
            canvasContext.restore();
        }
    }

    function logoExitPhase(seconds) {
        if (state.logoExitEffect === 'none') return 0;
        const raw = clamp((seconds - state.logoExitTime) / state.logoExitDuration, 0, 1);
        return easeBySetting(raw, state.logoEasing);
    }

    function drawRings(progress, seconds, centerY) {
        const style = state.motionStyle || MOTION_STYLES[0];
        const variant = style.variant;
        const ringIntro = easeBySetting(clamp((seconds - state.logoEntryTime) / Math.max(.45, state.logoEntryDuration * .72), 0, 1), state.logoEasing);
        const exitVisibility = state.logoExitEffect === 'none' ? 1 : 1 - logoExitPhase(seconds);
        if (ringIntro * exitVisibility <= .001) return;
        const cx = WIDTH / 2;
        const cy = centerY;
        const unit = Math.min(1, WIDTH / 1380);
        const familyWeight = ['orbit', 'pulse', 'burst', 'mask'].includes(style.family) ? 1 : .52;
        const offsetDirection = variant % 2 === 0 ? 1 : -1;

        canvasContext.save();
        canvasContext.globalAlpha = ringIntro * familyWeight * exitVisibility;
        [218, 270, 324].forEach((baseRadius, index) => {
            const radius = baseRadius * unit * (1 + (style.family === 'orbit' ? variant * .012 : 0));
            canvasContext.beginPath();
            canvasContext.ellipse(cx, cy, radius, radius * (.39 + variant * .008), style.family === 'orbit' ? (variant - 2) * .075 : 0, 0, Math.PI * 2);
            canvasContext.setLineDash(index === 1 ? [3 + variant, 15 + variant * 2] : [18 + variant * 2, 35]);
            canvasContext.lineDashOffset = -seconds * (18 + index * 8) * (index === 1 ? -1 : offsetDirection);
            canvasContext.lineWidth = index === 1 ? 2 : 1.4;
            canvasContext.strokeStyle = index === 1 ? rgba(state.gold, .48) : rgba(state.accent, .28 - index * .035);
            canvasContext.stroke();
        });
        canvasContext.setLineDash([]);
        canvasContext.globalAlpha = ringIntro * .6 * familyWeight * exitVisibility;
        canvasContext.beginPath();
        canvasContext.ellipse(cx, cy, Math.min(363 * unit, WIDTH * .47), Math.min(135 * unit, HEIGHT * .18), -.11, Math.PI * .94, Math.PI * 1.91);
        canvasContext.lineWidth = 1.6;
        canvasContext.strokeStyle = rgba(state.accent, .42);
        canvasContext.stroke();
        canvasContext.restore();

        const dotCount = 2 + (style.family === 'orbit' || style.family === 'burst' ? 1 : 0);
        for (let index = 0; index < dotCount; index++) {
            const angle = seconds * (.58 + index * .12) * (variant % 2 ? -1 : 1) + index * 2.1 - Math.PI / 2;
            const radiusX = (270 + (index % 2) * 54) * unit;
            const radiusY = radiusX * .42;
            const x = cx + Math.cos(angle) * radiusX;
            const y = cy + Math.sin(angle) * radiusY;
            const pulse = 1 + Math.sin(seconds * 2.3 + index) * .22;
            canvasContext.save();
            canvasContext.globalAlpha = ringIntro * familyWeight * exitVisibility * (.55 + Math.sin(seconds * 1.4 + index) * .14);
            canvasContext.shadowColor = index === 1 ? state.gold : state.accent;
            canvasContext.shadowBlur = 15;
            canvasContext.beginPath();
            canvasContext.arc(x, y, (index === 1 ? 4.1 : 3.1) * pulse, 0, Math.PI * 2);
            canvasContext.fillStyle = index === 1 ? state.gold : '#b4e8ff';
            canvasContext.fill();
            canvasContext.restore();
        }
    }

    function clipReveal(context, width, height, reveal, family, variant) {
        context.beginPath();
        const mode = variant % 5;
        if ((family === 'mask' && mode === 0) || (family === 'mask' && mode === 4)) {
            context.arc(0, 0, Math.hypot(width, height) * .56 * reveal, 0, Math.PI * 2);
        } else if (mode === 0) {
            context.rect(-width / 2, -height / 2, width * reveal, height);
        } else if (mode === 1) {
            context.rect(width / 2 - width * reveal, -height / 2, width * reveal, height);
        } else if (mode === 2) {
            context.rect(-width / 2, -height / 2, width, height * reveal);
        } else if (mode === 3) {
            context.rect(-width / 2, height / 2 - height * reveal, width, height * reveal);
        } else {
            const progressWidth = width * reveal;
            const skew = height * .44;
            context.moveTo(-width / 2, -height / 2);
            context.lineTo(-width / 2 + progressWidth + skew, -height / 2);
            context.lineTo(-width / 2 + progressWidth - skew, height / 2);
            context.lineTo(-width / 2 - skew, height / 2);
            context.closePath();
        }
        context.clip();
    }

    function drawBeam(centerX, centerY, width, height, reveal, variant, alpha) {
        if (reveal >= .99) return;
        const mode = variant % 5;
        const beamColor = state.gold;
        canvasContext.save();
        canvasContext.globalAlpha *= alpha;
        canvasContext.shadowColor = beamColor;
        canvasContext.shadowBlur = 22;
        if (mode <= 1) {
            const edgeX = mode === 0 ? centerX - width / 2 + width * reveal : centerX + width / 2 - width * reveal;
            const gradient = canvasContext.createLinearGradient(edgeX - 18, 0, edgeX + 18, 0);
            gradient.addColorStop(0, rgba(beamColor, 0));
            gradient.addColorStop(.5, rgba(beamColor, .9));
            gradient.addColorStop(1, rgba(beamColor, 0));
            canvasContext.fillStyle = gradient;
            canvasContext.fillRect(edgeX - 18, centerY - height / 2, 36, height);
        } else {
            const edgeY = mode === 2 ? centerY - height / 2 + height * reveal : centerY + height / 2 - height * reveal;
            const gradient = canvasContext.createLinearGradient(0, edgeY - 18, 0, edgeY + 18);
            gradient.addColorStop(0, rgba(beamColor, 0));
            gradient.addColorStop(.5, rgba(beamColor, .86));
            gradient.addColorStop(1, rgba(beamColor, 0));
            canvasContext.fillStyle = gradient;
            canvasContext.fillRect(centerX - width / 2, edgeY - 18, width, 36);
        }
        canvasContext.restore();
    }

    function drawLogo(progress, seconds, centerY) {
        const style = state.motionStyle || MOTION_STYLES[0];
        const family = style.family;
        const variant = style.variant;
        const cx = WIDTH / 2;
        const cy = centerY;
        const start = state.logoEntryTime + (variant % 3) * .035;
        const duration = state.logoEntryDuration * (1 + (variant % 5) * .025);
        const revealRaw = clamp((seconds - start) / duration, 0, 1);
        if (revealRaw <= 0) return revealRaw;
        const reveal = easeBySetting(revealRaw, state.logoEasing);
        const pop = state.logoEasing === 'spring' ? easeOutBack(revealRaw) : easeBySetting(revealRaw, state.logoEasing);
        const unit = Math.min(1, WIDTH / 1380);
        const cardSize = Math.min(278, Math.max(226, WIDTH * .22)) * state.logoScale;
        const cardX = cx - cardSize / 2;
        const cardY = cy - cardSize / 2;
        const imageSize = cardSize * .83;
        const cardAlpha = easeBySetting(clamp(revealRaw / .48, 0, 1), state.logoEasing);

        let scale = .92 + .08 * pop;
        let rotation = 0;
        let yShift = 0;
        let xShift = 0;
        let glow = .2;
        if (family === 'orbit') {
            rotation = (1 - reveal) * (variant % 2 ? 1 : -1) * (.12 + variant * .035);
        } else if (family === 'sweep') {
            scale = .96 + .04 * pop;
            glow = .3;
        } else if (family === 'pulse') {
            const settle = Math.sin(seconds * 4 + variant) * .009 * reveal;
            scale = (.76 + .24 * pop) * (1 + settle);
            glow = .34 + Math.sin(seconds * 3 + variant) * .04;
        } else if (family === 'spin') {
            const direction = variant === 3 ? -1 : 1;
            const turns = [.5, .25, 1, .75, 1.5][variant];
            rotation = direction * (1 - reveal) * Math.PI * 2 * turns;
            scale = .86 + .14 * pop;
        } else if (family === 'zoom') {
            scale = (.24 + .76 * pop) * (variant === 1 ? 1 + Math.sin(revealRaw * Math.PI) * .06 : 1);
            glow = .42;
        } else if (family === 'rise') {
            const direction = variant === 2 ? -1 : 1;
            yShift = direction * (1 - pop) * (90 + variant * 18) * unit;
            scale = .91 + .09 * pop;
        } else if (family === 'scan') {
            scale = .98 + .02 * pop;
            glow = .3;
        } else if (family === 'burst') {
            scale = .54 + .46 * pop;
            rotation = (1 - reveal) * (variant % 2 ? .12 : -.12);
            glow = .44;
        } else if (family === 'mask') {
            scale = .93 + .07 * pop;
            glow = .26;
        } else if (family === 'glitch') {
            const strength = (1 - reveal) * (variant === 0 ? 12 : 7) * unit;
            xShift = Math.sin(seconds * (28 + variant * 4)) * strength;
            yShift = Math.cos(seconds * (31 + variant * 3)) * strength * .35;
            scale = .95 + .05 * pop;
            glow = .3;
        }

        const keyedScale = evaluateKeyframes('logo', 'scale', seconds, 100) / 100;
        const keyedOpacity = evaluateKeyframes('logo', 'opacity', seconds, 100) / 100;
        const keyedRotation = evaluateKeyframes('logo', 'rotation', seconds, 0) * Math.PI / 180;
        const intensity = state.motionIntensity;
        scale = 1 + (scale - 1) * intensity;
        rotation *= intensity;
        xShift *= intensity;
        yShift *= intensity;
        const exitProgress = logoExitPhase(seconds);
        let exitAlpha = 1;
        let exitScale = 1;
        let exitX = 0;
        let exitY = 0;
        let exitRotation = 0;
        if (state.logoExitEffect === 'fade' || state.logoExitEffect === 'rise') exitAlpha = 1 - exitProgress;
        if (state.logoExitEffect === 'zoom') { exitScale = 1 - exitProgress * .38; exitAlpha = 1 - exitProgress; }
        if (state.logoExitEffect === 'rise') exitY = -exitProgress * 95 * unit;
        if (state.logoExitEffect === 'rotate') { exitRotation = exitProgress * Math.PI * .72; exitScale = 1 - exitProgress * .12; exitAlpha = 1 - exitProgress; }
        if (state.logoExitEffect === 'glitch') {
            const glitch = Math.sin(seconds * 42) * exitProgress * 16 * unit;
            exitX = glitch;
            exitY = Math.cos(seconds * 35) * exitProgress * 7 * unit;
            exitAlpha = 1 - exitProgress;
        }
        exitAlpha *= clamp(keyedOpacity, 0, 1);
        exitScale *= clamp(keyedScale, .5, 1.5);
        exitRotation += keyedRotation;
        if (exitAlpha <= .001) return reveal;
        canvasContext.save();
        canvasContext.globalAlpha *= exitAlpha;
        const logoAlpha = canvasContext.globalAlpha;
        canvasContext.translate(cx + exitX, cy + exitY);
        canvasContext.rotate(exitRotation);
        canvasContext.scale(exitScale, exitScale);
        canvasContext.translate(-cx, -cy);

        canvasContext.save();
        canvasContext.globalAlpha = logoAlpha * cardAlpha * .82;
        canvasContext.shadowColor = rgba(state.accent, .22);
        canvasContext.shadowBlur = 48 * unit;
        roundRectPath(canvasContext, cardX, cardY, cardSize, cardSize, 43 * unit);
        const plate = canvasContext.createLinearGradient(cardX, cardY, cardX + cardSize, cardY + cardSize);
        plate.addColorStop(0, 'rgba(32,54,85,.92)');
        plate.addColorStop(1, 'rgba(16,30,52,.96)');
        canvasContext.fillStyle = plate;
        canvasContext.fill();
        canvasContext.shadowBlur = 0;
        canvasContext.strokeStyle = rgba(state.accent, .26);
        canvasContext.lineWidth = 1.5;
        canvasContext.stroke();
        canvasContext.restore();

        canvasContext.save();
        canvasContext.globalAlpha = logoAlpha * cardAlpha * .58;
        canvasContext.strokeStyle = rgba(state.gold, .7);
        canvasContext.lineWidth = 2;
        const tick = 15 * unit;
        const pad = 16 * unit;
        [[cardX - pad, cardY - pad, 1, 1], [cardX + cardSize + pad, cardY - pad, -1, 1], [cardX - pad, cardY + cardSize + pad, 1, -1], [cardX + cardSize + pad, cardY + cardSize + pad, -1, -1]].forEach(([x, y, dx, dy]) => {
            canvasContext.beginPath();
            canvasContext.moveTo(x + dx * tick, y);
            canvasContext.lineTo(x, y);
            canvasContext.lineTo(x, y + dy * tick);
            canvasContext.stroke();
        });
        canvasContext.restore();

        if (state.logo && state.logo.complete && state.logo.naturalWidth > 0) {
            const imgWidth = Math.min(imageSize, imageSize * (state.logo.naturalWidth / Math.max(state.logo.naturalWidth, state.logo.naturalHeight)));
            const imgHeight = Math.min(imageSize, imageSize * (state.logo.naturalHeight / Math.max(state.logo.naturalWidth, state.logo.naturalHeight)));
            const revealFamily = ['sweep', 'scan', 'mask'].includes(family);
            const drawGhosts = family === 'glitch' && revealRaw < .72;

            canvasContext.save();
            canvasContext.translate(cx + xShift, cy + yShift);
            canvasContext.rotate(rotation);
            canvasContext.scale(scale, scale);
            canvasContext.shadowColor = rgba(state.accent, glow);
            canvasContext.shadowBlur = (family === 'zoom' || family === 'pulse' ? 35 : 20) * unit;
            canvasContext.globalAlpha = logoAlpha * (family === 'sweep' || family === 'scan' || family === 'mask' ? 1 : reveal);
            if (revealFamily) {
                canvasContext.save();
                clipReveal(canvasContext, imgWidth, imgHeight, reveal, family, variant);
                canvasContext.drawImage(state.logo, -imgWidth / 2, -imgHeight / 2, imgWidth, imgHeight);
                canvasContext.restore();
            } else {
                if (drawGhosts) {
                    canvasContext.save();
                    canvasContext.globalCompositeOperation = 'screen';
                    canvasContext.globalAlpha = logoAlpha * (1 - reveal) * .28;
                    canvasContext.shadowBlur = 0;
                    canvasContext.drawImage(state.logo, -imgWidth / 2 - 5 * unit, -imgHeight / 2, imgWidth, imgHeight);
                    canvasContext.globalAlpha = logoAlpha * (1 - reveal) * .18;
                    canvasContext.drawImage(state.logo, -imgWidth / 2 + 5 * unit, -imgHeight / 2 + 2 * unit, imgWidth, imgHeight);
                    canvasContext.restore();
                }
                canvasContext.drawImage(state.logo, -imgWidth / 2, -imgHeight / 2, imgWidth, imgHeight);
            }
            canvasContext.restore();

            if (family === 'sweep' || family === 'scan') drawBeam(cx + xShift, cy + yShift, imgWidth, imgHeight, reveal, variant, cardAlpha);
            if (family === 'glitch' && revealRaw < .75) {
                canvasContext.save();
                canvasContext.globalAlpha = logoAlpha * (1 - reveal) * .2;
                canvasContext.strokeStyle = state.gold;
                for (let line = 0; line < 4; line++) {
                    const y = cy - imgHeight * .35 + line * imgHeight * .22 + Math.sin(seconds * 31 + line + variant) * 5;
                    canvasContext.beginPath();
                    canvasContext.moveTo(cx - imgWidth * .48, y);
                    canvasContext.lineTo(cx + imgWidth * .48, y);
                    canvasContext.stroke();
                }
                canvasContext.restore();
            }
        }

        if (family === 'pulse' && revealRaw > .4) {
            const haloProgress = ((progress * (1.2 + variant * .12)) % .36) / .36;
            const radius = cardSize * .58 + haloProgress * cardSize * .32;
            canvasContext.save();
            canvasContext.globalAlpha = logoAlpha * (1 - haloProgress) * .25;
            canvasContext.beginPath();
            canvasContext.arc(cx, cy, radius, 0, Math.PI * 2);
            canvasContext.strokeStyle = state.accent;
            canvasContext.lineWidth = 2;
            canvasContext.stroke();
            canvasContext.restore();
        }
        canvasContext.restore();
        return reveal;
    }

    function drawLogoCopies(seconds, baseY) {
        if (!state.logo || !state.logo.complete || state.logo.naturalWidth <= 0) return;
        state.logoLayers.forEach(layer => {
            const entryRaw = clamp((seconds - layer.entryTime) / Math.max(.1, layer.entryDuration), 0, 1);
            if (entryRaw <= 0) return;
            const exitRaw = clamp((seconds - layer.exitTime) / Math.max(.1, layer.exitDuration), 0, 1);
            const entry = easeBySetting(entryRaw, state.logoEasing);
            const exit = easeBySetting(exitRaw, state.logoEasing);
            const key = `logoLayer:${layer.id}`;
            const opacity = evaluateKeyframes(key, 'opacity', seconds, Number(layer.opacity) || 0) / 100;
            const keyedScale = evaluateKeyframes(key, 'scale', seconds, Number(layer.scale) || 42) / 100;
            const xOffset = evaluateKeyframes(key, 'xOffset', seconds, Number(layer.xOffset) || 0);
            const yOffset = evaluateKeyframes(key, 'yOffset', seconds, Number(layer.yOffset) || 0);
            const rotation = evaluateKeyframes(key, 'rotation', seconds, 0) * Math.PI / 180;
            const side = clamp(Math.min(250, WIDTH * .2) * keyedScale, 44, 280);
            const fit = Math.min(side / state.logo.naturalWidth, side / state.logo.naturalHeight);
            const imageWidth = state.logo.naturalWidth * fit;
            const imageHeight = state.logo.naturalHeight * fit;
            const x = WIDTH / 2 + WIDTH * xOffset / 100;
            const y = baseY + HEIGHT * yOffset / 100;
            const popScale = .78 + .22 * entry;
            const alpha = clamp(entry * (1 - exit) * opacity, 0, 1);
            if (alpha <= .001) return;
            canvasContext.save();
            canvasContext.translate(x, y);
            canvasContext.rotate(rotation);
            canvasContext.scale(popScale, popScale);
            canvasContext.globalAlpha *= alpha;
            canvasContext.shadowColor = rgba(state.accent, .42);
            canvasContext.shadowBlur = 24 * Math.min(1, WIDTH / 1400);
            canvasContext.drawImage(state.logo, -imageWidth / 2, -imageHeight / 2, imageWidth, imageHeight);
            canvasContext.restore();
        });
    }

    function drawSparkles(progress, seconds, centerY) {
        const style = state.motionStyle || MOTION_STYLES[0];
        const strength = style.family === 'burst' ? 1.6 : (style.family === 'glitch' ? .7 : 1);
        const start = easeBySetting(clamp((seconds - state.logoEntryTime - state.logoEntryDuration * .35) / .7, 0, 1), state.logoEasing);
        const exitVisibility = state.logoExitEffect === 'none' ? 1 : 1 - logoExitPhase(seconds);
        if (start * exitVisibility <= .001) return;
        const cx = WIDTH / 2;
        const cy = centerY;
        const radius = Math.min(WIDTH * .22, 296);
        const sparkles = [
            [-.72, -.42, .4], [.72, -.43, 1.8], [-.69, .45, 2.8], [.7, .42, .9],
            [0, -.72, 1.5], [0, .73, 2.5], [-.97, .02, .4], [.98, 0, 2.3]
        ];
        sparkles.forEach(([rx, ry, phase], index) => {
            const alpha = start * exitVisibility * strength * (.13 + (Math.sin(seconds * 2.4 + phase) + 1) * .22);
            const size = (2.2 + ((index + 1) % 3) * .8) * Math.min(1, WIDTH / 1400);
            const x = cx + rx * radius + Math.sin(seconds * .55 + phase) * 3;
            const y = cy + ry * radius + Math.cos(seconds * .7 + phase) * 4;
            canvasContext.save();
            canvasContext.translate(x, y);
            canvasContext.rotate(seconds * .12 + phase);
            canvasContext.globalAlpha = alpha;
            canvasContext.fillStyle = index % 3 === 0 ? state.gold : '#d8eeff';
            canvasContext.shadowColor = index % 3 === 0 ? state.gold : state.accent;
            canvasContext.shadowBlur = 13;
            canvasContext.beginPath();
            canvasContext.moveTo(0, -size * 2.7);
            canvasContext.quadraticCurveTo(size * .34, -size * .34, size * 2.3, 0);
            canvasContext.quadraticCurveTo(size * .3, size * .3, 0, size * 2.7);
            canvasContext.quadraticCurveTo(-size * .3, size * .3, -size * 2.3, 0);
            canvasContext.quadraticCurveTo(-size * .3, -size * .3, 0, -size * 2.7);
            canvasContext.fill();
            canvasContext.restore();
        });
    }

    function drawPhoneIcon(x, y, size) {
        canvasContext.save();
        canvasContext.translate(x, y);
        canvasContext.scale(size / 24, size / 24);
        canvasContext.beginPath();
        canvasContext.moveTo(7, 3.5);
        canvasContext.lineTo(4.8, 4.6);
        canvasContext.quadraticCurveTo(4, 5, 4.4, 6.7);
        canvasContext.quadraticCurveTo(8, 15.5, 17.3, 19.5);
        canvasContext.quadraticCurveTo(18.8, 20.2, 19.5, 18.8);
        canvasContext.lineTo(20.6, 16.5);
        canvasContext.lineTo(15.7, 13.7);
        canvasContext.lineTo(13.8, 15.7);
        canvasContext.quadraticCurveTo(9.2, 13.3, 7.2, 8.7);
        canvasContext.lineTo(9.1, 6.8);
        canvasContext.closePath();
        canvasContext.stroke();
        canvasContext.restore();
    }

    function drawGlobeIcon(x, y, size) {
        canvasContext.save();
        canvasContext.translate(x, y);
        canvasContext.scale(size / 24, size / 24);
        canvasContext.beginPath();
        canvasContext.arc(12, 12, 8.5, 0, Math.PI * 2);
        canvasContext.moveTo(3.8, 12); canvasContext.lineTo(20.2, 12);
        canvasContext.moveTo(12, 3.5); canvasContext.bezierCurveTo(8, 7.5, 8, 16.5, 12, 20.5);
        canvasContext.moveTo(12, 3.5); canvasContext.bezierCurveTo(16, 7.5, 16, 16.5, 12, 20.5);
        canvasContext.stroke();
        canvasContext.restore();
    }

    function styleForText(key) {
        return state.textStyles[key] || state.textStyles.title;
    }

    function animatedTextStyle(key, seconds) {
        const base = styleForText(key);
        const next = { ...base };
        ['opacity', 'fontSize', 'xOffset', 'yOffset', 'letterSpacing'].forEach(property => {
            next[property] = evaluateKeyframes(key, property, seconds, Number(base[property]) || 0);
        });
        return next;
    }

    function textItemForKey(key) {
        return allTextItems().find(item => item.key === key) || TEXT_ITEMS[0];
    }

    function textPhase(key, seconds) {
        const style = styleForText(key);
        const entryRaw = clamp((seconds - style.entryTime) / Math.max(.1, style.entryDuration), 0, 1);
        const exitRaw = style.exitEffect === 'none' ? 0 : clamp((seconds - style.exitTime) / Math.max(.1, style.exitDuration), 0, 1);
        const entry = easeBySetting(entryRaw, style.easing);
        const exit = easeBySetting(exitRaw, style.easing);
        return { entry, exit, alpha: entry * (1 - exit) };
    }

    function graphemes(text, locale) {
        if (typeof Intl !== 'undefined' && Intl.Segmenter) {
            return Array.from(new Intl.Segmenter(locale || 'und', { granularity: 'grapheme' }).segment(text), item => item.segment);
        }
        return Array.from(text);
    }

    function typewriterText(text, progress, locale) {
        const characters = graphemes(text, locale);
        return characters.slice(0, Math.max(1, Math.ceil(characters.length * progress))).join('');
    }

    function fontCss(style) {
        const family = String(style.fontFamily || 'Vazirmatn').replace(/["\\]/g, '');
        const generic = ['serif', 'sans-serif', 'monospace', 'cursive', 'fantasy', 'system-ui'].includes(family.toLowerCase());
        const familyCss = generic ? family : `"${family}"`;
        return `${style.italic ? 'italic ' : ''}${style.fontWeight || '500'} ${Math.max(8, Number(style.fontSize) || 18)}px ${familyCss}, sans-serif`;
    }

    function measureStyledText(text, style) {
        canvasContext.save();
        canvasContext.font = fontCss(style);
        const width = canvasContext.measureText(text).width;
        canvasContext.restore();
        return width + Math.max(0, graphemes(text).length - 1) * (Number(style.letterSpacing) || 0);
    }

    function drawLetterSpacedText(text, x, y, maxWidth, spacing, alignment) {
        const gap = Math.max(-2, Number(spacing) || 0);
        const align = ['left', 'right'].includes(alignment) ? alignment : 'center';
        if ('letterSpacing' in canvasContext) {
            const previousSpacing = canvasContext.letterSpacing;
            const previousAlign = canvasContext.textAlign;
            canvasContext.letterSpacing = `${gap}px`;
            canvasContext.textAlign = align;
            canvasContext.fillText(text, x, y, maxWidth);
            canvasContext.letterSpacing = previousSpacing;
            canvasContext.textAlign = previousAlign;
            return;
        }
        const characters = graphemes(text);
        const widths = characters.map(character => canvasContext.measureText(character).width);
        const totalWidth = widths.reduce((sum, width) => sum + width, 0) + Math.max(0, characters.length - 1) * gap;
        if (!characters.length || totalWidth <= 0) return;
        const scale = Math.min(1, maxWidth / totalWidth);
        canvasContext.save();
        canvasContext.translate(x, y);
        canvasContext.scale(scale, 1);
        canvasContext.textAlign = 'center';
        let cursor = align === 'left' ? 0 : (align === 'right' ? -totalWidth : -totalWidth / 2);
        characters.forEach((character, index) => {
            canvasContext.fillText(character, cursor + widths[index] / 2, 0);
            cursor += widths[index] + gap;
        });
        canvasContext.restore();
    }

    function textColor(key, style) {
        if (!style.autoColor) return style.color || '#f4f7fd';
        const light = colorIsLight(state.background);
        const item = textItemForKey(key);
        const semanticKey = item.templateKey || key;
        if (semanticKey === 'title') return light ? '#172238' : '#f4f7fd';
        if (semanticKey === 'tagline') return light ? '#526078' : '#b9c7dc';
        if (semanticKey === 'phone' || semanticKey === 'website') return light ? '#35445d' : '#d2def0';
        return light ? '#67758b' : '#9db1cc';
    }

    function withTextMotion(key, seconds, anchorX, anchorY, maxWidth, lineHeight, draw) {
        const style = animatedTextStyle(key, seconds);
        const phase = textPhase(key, seconds);
        if (phase.alpha <= .001 || phase.entry <= 0) return;
        let x = 0;
        let y = 0;
        let scaleX = 1;
        let scaleY = 1;
        let rotation = 0;
        let blur = 0;
        const enter = style.enterEffect;
        const exit = style.exitEffect;
        const distance = Math.max(20, Math.min(110, lineHeight * 1.15));

        if (enter === 'rise') y += (1 - phase.entry) * distance * .55;
        else if (enter === 'slide-left') x += (1 - phase.entry) * distance;
        else if (enter === 'slide-right') x -= (1 - phase.entry) * distance;
        else if (enter === 'zoom') { scaleX *= .72 + .28 * phase.entry; scaleY *= .72 + .28 * phase.entry; }
        else if (enter === 'rotate') rotation -= (1 - phase.entry) * .13;
        else if (enter === 'blur') blur += (1 - phase.entry) * 10;

        if (exit === 'descend') y += phase.exit * distance * .45;
        else if (exit === 'slide-left') x -= phase.exit * distance;
        else if (exit === 'slide-right') x += phase.exit * distance;
        else if (exit === 'zoom') { scaleX *= 1 - phase.exit * .22; scaleY *= 1 - phase.exit * .22; }
        else if (exit === 'rotate') rotation += phase.exit * .15;
        else if (exit === 'blur') blur += phase.exit * 10;

        const textLeft = style.alignment === 'left' ? 0 : (style.alignment === 'right' ? -maxWidth : -maxWidth / 2);
        canvasContext.save();
        canvasContext.globalAlpha *= phase.alpha * clamp((Number(style.opacity) || 0) / 100, 0, 1);
        canvasContext.translate(anchorX + x, anchorY + y);
        canvasContext.rotate(rotation);
        canvasContext.scale(scaleX, scaleY);
        if (blur > .1 && 'filter' in canvasContext) canvasContext.filter = `blur(${Math.min(14, blur)}px)`;
        if (enter === 'wipe') {
            const visible = maxWidth * phase.entry;
            canvasContext.beginPath();
            canvasContext.rect(textLeft, -lineHeight, visible, lineHeight * 2);
            canvasContext.clip();
        }
        if (exit === 'wipe') {
            const visible = maxWidth * (1 - phase.exit);
            canvasContext.beginPath();
            canvasContext.rect(textLeft + maxWidth - visible, -lineHeight, visible, lineHeight * 2);
            canvasContext.clip();
        }
        draw(phase, style);
        canvasContext.restore();
    }

    function drawStyledText(key, value, seconds, baseY) {
        const text = String(value || '').trim();
        if (!text) return;
        const style = animatedTextStyle(key, seconds);
        const item = textItemForKey(key);
        const maxWidth = Math.min(WIDTH * .96, WIDTH * clamp(Number(style.maxWidth) || 80, 20, 96) / 100);
        const anchorX = WIDTH / 2 + WIDTH * (Number(style.xOffset) || 0) / 100;
        const anchorY = baseY + HEIGHT * (Number(style.yOffset) || 0) / 100;
        withTextMotion(key, seconds, anchorX, anchorY, maxWidth, Number(style.fontSize) * 1.55, phase => {
            let shown = text;
            if (key === 'english' || item.templateKey === 'english') {
                if (style.caseMode === 'upper') shown = text.toLocaleUpperCase('en');
                else if (style.caseMode === 'lower') shown = text.toLocaleLowerCase('en');
            }
            if (style.enterEffect === 'type') shown = typewriterText(shown, phase.entry, item.rtl ? 'fa' : 'en');
            canvasContext.font = fontCss(style);
            canvasContext.direction = item.rtl ? 'rtl' : 'ltr';
            canvasContext.textAlign = style.alignment;
            canvasContext.textBaseline = 'alphabetic';
            canvasContext.fillStyle = textColor(key, style);
            canvasContext.shadowColor = style.shadowColor || '#07101f';
            canvasContext.shadowBlur = Math.max(0, Number(style.shadowBlur) || 0);
            if (Number(style.strokeWidth) > 0) {
                canvasContext.lineWidth = Number(style.strokeWidth);
                canvasContext.strokeStyle = style.strokeColor || '#07101f';
                canvasContext.lineJoin = 'round';
                canvasContext.strokeText(shown, 0, 0, maxWidth);
            }
            drawLetterSpacedText(shown, 0, 0, maxWidth, style.letterSpacing, style.alignment);
        });
    }

    function drawContactRow(seconds) {
        const values = [
            { key: 'website', icon: 'web', value: state.website.trim().slice(0, 48) },
            { key: 'phone', icon: 'phone', value: state.phone.trim().slice(0, 28) },
            ...state.extraTextLayers.filter(item => ['phone', 'website'].includes(item.templateKey)).map(item => ({ key: item.key, icon: item.icon || (item.templateKey === 'phone' ? 'phone' : 'web'), value: String(item.value || '').trim().slice(0, 48) }))
        ].filter(item => item.value);
        if (!values.length) return;
        const gap = values.length > 1 ? 14 : 0;
        const widths = values.map(item => {
            const style = animatedTextStyle(item.key, seconds);
            const textWidth = measureStyledText(item.value, style);
            const cap = Math.min(WIDTH * .44, WIDTH * clamp(Number(style.maxWidth) || 38, 20, 80) / 100);
            return clamp(textWidth, 60, cap) + 60;
        });
        const availableWidth = WIDTH * .9 - gap * Math.max(0, values.length - 1);
        const widthTotal = widths.reduce((sum, width) => sum + width, 0);
        if (widthTotal > availableWidth) {
            const scale = availableWidth / widthTotal;
            widths.forEach((width, index) => { widths[index] = Math.max(120, width * scale); });
        }
        const totalWidth = widths.reduce((sum, width) => sum + width, 0) + gap * Math.max(0, values.length - 1);
        let cursor = WIDTH / 2 - totalWidth / 2;
        const light = colorIsLight(state.background);

        values.forEach((item, index) => {
            const style = animatedTextStyle(item.key, seconds);
            const itemWidth = widths[index];
            const centerX = cursor + itemWidth / 2 + WIDTH * (Number(style.xOffset) || 0) / 100;
            const centerY = HEIGHT * .79 + HEIGHT * (Number(style.yOffset) || 0) / 100;
            const innerLeft = -itemWidth / 2 + 43;
            const innerWidth = itemWidth - 53;
            const textAnchor = style.alignment === 'left' ? innerLeft : (style.alignment === 'right' ? innerLeft + innerWidth : innerLeft + innerWidth / 2);
            withTextMotion(item.key, seconds, centerX, centerY, innerWidth, 52, phase => {
                const boxX = -itemWidth / 2;
                const boxY = -21;
                roundRectPath(canvasContext, boxX, boxY, itemWidth, 42, 14);
                canvasContext.fillStyle = light ? 'rgba(29,48,77,.055)' : 'rgba(223,236,255,.055)';
                canvasContext.fill();
                canvasContext.strokeStyle = light ? 'rgba(41,64,97,.13)' : 'rgba(213,229,251,.13)';
                canvasContext.lineWidth = 1;
                canvasContext.stroke();
                canvasContext.save();
                canvasContext.strokeStyle = state.gold;
                canvasContext.lineWidth = 1.6;
                if (item.icon === 'phone') drawPhoneIcon(boxX + 13, boxY + 10, 22);
                else drawGlobeIcon(boxX + 13, boxY + 10, 22);
                canvasContext.restore();
                let shown = item.value;
                if (style.enterEffect === 'type') shown = typewriterText(item.value, phase.entry, 'en');
                canvasContext.font = fontCss(style);
                canvasContext.direction = 'ltr';
                canvasContext.textAlign = style.alignment;
                canvasContext.textBaseline = 'middle';
                canvasContext.fillStyle = textColor(item.key, style);
                canvasContext.shadowColor = style.shadowColor || '#07101f';
                canvasContext.shadowBlur = Math.max(0, Number(style.shadowBlur) || 0);
                if (Number(style.strokeWidth) > 0) {
                    canvasContext.lineWidth = Number(style.strokeWidth);
                    canvasContext.strokeStyle = style.strokeColor || '#07101f';
                    canvasContext.strokeText(shown, textAnchor, -1, innerWidth);
                }
                drawLetterSpacedText(shown, textAnchor, -1, innerWidth, style.letterSpacing, style.alignment);
            });
            cursor += itemWidth + gap;
        });
    }

    function drawLockup(seconds) {
        const titleY = HEIGHT * .601;
        const titleStyle = animatedTextStyle('title', seconds);
        const titleAnchorX = WIDTH / 2 + WIDTH * (Number(titleStyle.xOffset) || 0) / 100;
        drawStyledText('title', state.title || 'نام برند شما', seconds, titleY);

        const titlePhase = textPhase('title', seconds);
        if (titlePhase.alpha > .001 && titlePhase.entry > 0) {
            const lineWidth = Math.min(350, WIDTH * .3) * easeOutCubic(titlePhase.entry);
            const left = titleAnchorX - lineWidth / 2;
            const lineY = titleY + HEIGHT * (Number(titleStyle.yOffset) || 0) / 100 + Number(titleStyle.fontSize) * .78;
            const line = canvasContext.createLinearGradient(left, 0, left + lineWidth, 0);
            line.addColorStop(0, rgba(state.accent, 0));
            line.addColorStop(.22, rgba(state.accent, .88));
            line.addColorStop(.52, rgba(state.gold, .98));
            line.addColorStop(.82, rgba(state.accent, .8));
            line.addColorStop(1, rgba(state.accent, 0));
            canvasContext.save();
            canvasContext.globalAlpha *= titlePhase.alpha * clamp(Number(titleStyle.opacity) / 100, 0, 1);
            canvasContext.shadowColor = state.gold;
            canvasContext.shadowBlur = 14;
            canvasContext.fillStyle = line;
            roundRectPath(canvasContext, left, lineY, Math.max(1, lineWidth), 3, 2);
            canvasContext.fill();
            canvasContext.restore();
        }

        drawStyledText('tagline', state.tagline, seconds, HEIGHT * .709);
        drawContactRow(seconds);
        drawStyledText('english', state.englishText, seconds, HEIGHT * .866);
        state.extraTextLayers.filter(item => !['phone', 'website'].includes(item.templateKey)).forEach(item => {
            drawStyledText(item.key, item.value, seconds, HEIGHT * clamp(Number(item.defaultY) || 52, 4, 96) / 100);
        });
    }

    function drawFrame(seconds) {
        const time = clamp(seconds, 0, state.duration);
        const progress = state.duration > 0 ? time / state.duration : 0;
        const scaleX = canvas.width / WIDTH;
        const scaleY = canvas.height / HEIGHT;
        canvasContext.setTransform(1, 0, 0, 1, 0, 0);
        canvasContext.clearRect(0, 0, canvas.width, canvas.height);
        canvasContext.setTransform(scaleX, 0, 0, scaleY, 0, 0);
        canvasContext.lineCap = 'round';
        canvasContext.lineJoin = 'round';
        drawBackground(progress, time);
        const logoY = HEIGHT * .357;
        drawRings(progress, time, logoY);
        drawLogo(progress, time, logoY);
        drawLogoCopies(time, logoY);
        drawSparkles(progress, time, logoY);
        drawLockup(time);
    }

    function formatClock(seconds) {
        const whole = Math.floor(Math.max(0, seconds));
        const minutes = Math.floor(whole / 60).toLocaleString('fa-IR', { minimumIntegerDigits: 2, useGrouping: false });
        const rest = (whole % 60).toLocaleString('fa-IR', { minimumIntegerDigits: 2, useGrouping: false });
        return `${minutes}:${rest}`;
    }

    function currentTime(now) {
        if (state.exporting) return clamp(state.exportFrameIndex / Math.max(1, state.fps), 0, state.duration);
        if (!state.playing) return state.offset;
        const elapsed = Math.max(0, now - state.startedAt) / 1000;
        return (state.offset + elapsed) % state.duration;
    }

    function updateTransport(seconds) {
        const fraction = clamp(seconds / state.duration, 0, 1);
        if (!state.scrubbing) el.range.value = String(Math.round(fraction * 1000));
        el.fill.style.width = `${fraction * 100}%`;
        el.currentTime.textContent = formatClock(seconds);
        el.totalTime.textContent = formatClock(state.duration);
        el.specDuration.textContent = `${state.duration.toLocaleString('fa-IR')} ثانیه`;
        el.playIcon.hidden = state.playing || state.exporting;
        el.pauseIcon.hidden = !state.playing || state.exporting;
        el.play.setAttribute('aria-label', state.playing ? 'توقف پخش' : 'ادامه‌ی پخش');
        el.play.title = state.playing ? 'توقف پخش' : 'ادامه‌ی پخش';
    }

    function keyframeDefinition(target, property) {
        let definitions = Object.prototype.hasOwnProperty.call(KEYFRAME_PROPERTIES, target) ? KEYFRAME_PROPERTIES[target] : [];
        if (String(target).startsWith('logoLayer:')) definitions = LOGO_LAYER_KEYFRAME_PROPERTIES;
        else if (String(target).startsWith('audioTrack:')) definitions = AUDIO_KEYFRAME_PROPERTIES;
        return definitions.find(item => item.key === property) || null;
    }

    function getKeyframeBaseValue(target, property) {
        if (target === 'logo') {
            if (property === 'scale') return 100;
            if (property === 'opacity') return 100;
            if (property === 'rotation') return 0;
        }
        if (String(target).startsWith('logoLayer:')) {
            const id = String(target).slice('logoLayer:'.length);
            const layer = state.logoLayers.find(item => item.id === id);
            if (layer && Object.prototype.hasOwnProperty.call(layer, property)) return Number(layer[property]) || 0;
        }
        if (String(target).startsWith('audioTrack:') && property === 'volume') {
            const id = String(target).slice('audioTrack:'.length);
            const track = state.audioTracks.find(item => item.id === id);
            return track ? (Number(track.volume) || 0) * 100 : 0;
        }
        const style = state.textStyles[target];
        if (style && Object.prototype.hasOwnProperty.call(style, property)) return Number(style[property]) || 0;
        return 0;
    }

    function evaluateKeyframes(target, property, time, baseValue) {
        const frames = state.keyframes.filter(frame => frame.target === target && frame.property === property).sort((a, b) => a.time - b.time);
        if (!frames.length) return Number(baseValue) || 0;
        const position = clamp(Number(time) || 0, 0, state.duration);
        if (position < frames[0].time) return Number(baseValue) || 0;
        if (position === frames[0].time || frames.length === 1) return frames[0].value;
        for (let index = 0; index < frames.length - 1; index += 1) {
            const from = frames[index];
            const to = frames[index + 1];
            if (position <= to.time) {
                const span = Math.max(.001, to.time - from.time);
                const progress = easeBySetting((position - from.time) / span, to.easing || 'cinematic');
                return from.value + (to.value - from.value) * progress;
            }
        }
        return frames[frames.length - 1].value;
    }

    function sanitizeImportedKeyframes(value, duration) {
        if (!Array.isArray(value)) return [];
        const easingNames = new Set(EASING_OPTIONS.map(option => option[0]));
        const result = [];
        value.slice(0, 500).forEach((frame, index) => {
            if (!frame || typeof frame !== 'object') return;
            const target = String(frame.target || '');
            const property = String(frame.property || '');
            const definition = keyframeDefinition(target, property);
            const targetExists = target === 'logo' || TEXT_ITEMS.some(item => item.key === target) || state.extraTextLayers.some(item => item.key === target)
                || (target.startsWith('logoLayer:') && state.logoLayers.some(item => item.id === target.slice('logoLayer:'.length)))
                || (target.startsWith('audioTrack:') && state.audioTracks.some(item => item.id === target.slice('audioTrack:'.length)));
            const time = Number(frame.time);
            const amount = Number(frame.value);
            if (!definition || !targetExists || !Number.isFinite(time) || !Number.isFinite(amount)) return;
            const id = typeof frame.id === 'string' && frame.id.length < 120 ? frame.id : `kf-import-${index}`;
            result.push({
                id,
                target,
                property,
                time: Math.round(clamp(time, 0, duration) * 100) / 100,
                value: clamp(amount, definition.min, definition.max),
                easing: easingNames.has(frame.easing) ? frame.easing : 'cinematic'
            });
        });
        state.keyframeSequence = result.length;
        return result;
    }

    function renderTimelineRuler() {
        if (!el.timelineRuler) return;
        el.timelineRuler.replaceChildren();
        for (let second = 0; second <= state.duration; second += 1) {
            const tick = document.createElement('span');
            tick.className = 'lm-ruler-tick';
            tick.style.left = `${(second / state.duration) * 100}%`;
            tick.textContent = second === state.duration ? `${second.toLocaleString('fa-IR')}ث` : second.toLocaleString('fa-IR');
            el.timelineRuler.appendChild(tick);
        }
    }

    function renderKeyframeList() {
        if (!el.keyframeList) return;
        el.keyframeList.replaceChildren();
        const sorted = [...state.keyframes].sort((a, b) => a.time - b.time);
        el.keyframeCount.textContent = `${sorted.length.toLocaleString('fa-IR')} کی‌فریم`;
        if (!sorted.length) {
            const empty = document.createElement('span');
            empty.className = 'lm-keyframe-empty';
            empty.textContent = 'برای ساخت انیمیشن، نشانگر را جابه‌جا کنید و یک ویژگی را ثبت کنید.';
            el.keyframeList.appendChild(empty);
            return;
        }
        sorted.slice(0, 120).forEach(frame => {
            const targetLabel = keyframeTargetLabel(frame.target);
            const property = keyframeDefinition(frame.target, frame.property);
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'lm-keyframe-chip' + (frame.id === state.selectedKeyframeId ? ' is-selected' : '');
            button.textContent = `${targetLabel || frame.target} · ${property ? property.label : frame.property} · ${secondsLabel(frame.time)}`;
            button.setAttribute('aria-label', `${button.textContent}، مقدار ${Number(frame.value).toLocaleString('fa-IR')}`);
            button.addEventListener('click', () => selectKeyframe(frame.id, true));
            el.keyframeList.appendChild(button);
        });
        if (sorted.length > 120) {
            const more = document.createElement('span');
            more.className = 'lm-keyframe-empty';
            more.textContent = `و ${ (sorted.length - 120).toLocaleString('fa-IR') } کی‌فریم دیگر در تایم‌لاین`;
            el.keyframeList.appendChild(more);
        }
    }

    function timelineTargets() {
        const targets = [
            { key: 'logo', label: 'لوگوی اصلی', kind: 'logo', keyframe: true },
            ...TEXT_ITEMS.map(item => ({ key: item.key, label: item.label, kind: 'text', keyframe: true })),
            ...state.logoLayers.map(layer => ({ key: `logoLayer:${layer.id}`, label: layer.label || 'کپی لوگو', kind: 'logoLayer', layerId: layer.id, keyframe: true })),
            ...state.extraTextLayers.map(item => ({ key: item.key, label: item.label, kind: 'text', keyframe: true })),
            ...state.audioTracks.map(track => ({ key: `audioTrack:${track.id}`, label: `${track.kind === 'voice' ? 'گفتار' : 'موسیقی'} · ${track.name}`, kind: 'audio', trackId: track.id, keyframe: true }))
        ];
        if (!state.audioTracks.length) targets.push({ key: 'audioEmpty', label: 'موسیقی و گفتار', kind: 'audioEmpty', keyframe: false });
        return targets;
    }

    function keyframeTargetLabel(key) {
        const target = timelineTargets().find(item => item.key === key);
        return target ? target.label : '';
    }

    function keyframeDefinitionsForTarget(target) {
        if (String(target).startsWith('logoLayer:')) return LOGO_LAYER_KEYFRAME_PROPERTIES;
        if (String(target).startsWith('audioTrack:')) return AUDIO_KEYFRAME_PROPERTIES;
        return Object.prototype.hasOwnProperty.call(KEYFRAME_PROPERTIES, target) ? KEYFRAME_PROPERTIES[target] : [];
    }

    function updateKeyframeTargetOptions() {
        if (!el.keyframeTarget) return;
        const previous = el.keyframeTarget.value;
        const targets = timelineTargets().filter(target => target.keyframe);
        el.keyframeTarget.replaceChildren();
        targets.forEach(target => {
            const option = document.createElement('option');
            option.value = target.key;
            option.textContent = target.label;
            el.keyframeTarget.appendChild(option);
        });
        el.keyframeTarget.value = targets.some(target => target.key === previous) ? previous : (targets[0] ? targets[0].key : 'logo');
        updateKeyframePropertyOptions();
    }

    function renderTimelineTracks() {
        if (!el.timelineTracks || !el.timelineRuler) return;
        if (state.timelinePointerDrag) {
            state.timelineRenderPending = true;
            return;
        }
        state.timelineRenderPending = false;
        renderTimelineRuler();
        el.timelineTracks.replaceChildren();
        const duration = Math.max(.1, state.duration);
        updateKeyframeTargetOptions();
        if (el.timelineAddAudio) el.timelineAddAudio.classList.toggle('has-audio', state.audioTracks.some(track => track.kind === 'music'));
        if (el.timelineAddVoice) el.timelineAddVoice.classList.toggle('has-audio', state.audioTracks.some(track => track.kind === 'voice'));

        const chooseAudioFile = (kind = 'music') => {
            if (state.exporting || state.exportPreparing) return;
            const input = kind === 'voice' ? el.voiceFile : el.audioFile;
            if (input) input.click();
        };
        const timeAt = (clientX, lane) => {
            const rect = lane.getBoundingClientRect();
            return rect.width ? clamp((clientX - rect.left) / rect.width * duration, 0, duration) : 0;
        };
        const setTrackStart = (track, value, commit) => {
            if (!track) return;
            track.start = clamp(Number(value) || 0, 0, duration);
            if (commit) track.start = Math.round(track.start * 10) / 10;
            if (getSelectedAudioTrack() === track) {
                syncAudioAliases(track);
                el.audioStart.max = String(state.duration);
                el.audioStart.value = track.start.toFixed(1);
                el.audioStartValue.textContent = secondsLabel(track.start);
            }
            if (commit) {
                state.audioLastSync = 0;
                syncAudioPlayback(currentTime(performance.now()), true);
            }
        };
        const placeAudioClip = (clip, track) => {
            const start = clamp(Number(track.start) || 0, 0, duration);
            const end = track.loop || !track.duration ? duration : Math.min(duration, start + track.duration);
            clip.style.left = `${(start / duration) * 100}%`;
            clip.style.width = `${Math.max(.6, ((end - start) / duration) * 100)}%`;
            clip.setAttribute('aria-valuenow', start.toFixed(1));
            clip.setAttribute('aria-valuetext', `${secondsLabel(start)}؛ شروع ${track.kind === 'voice' ? 'گفتار' : 'موسیقی'}`);
        };

        timelineTargets().forEach(target => {
            const row = document.createElement('div');
            const kindClass = target.kind === 'audio' ? (state.audioTracks.find(track => track.id === target.trackId)?.kind || 'music') : target.kind;
            const rowKind = target.kind === 'audioEmpty' ? 'audio-empty' : (target.kind === 'audio' ? `audio-${kindClass}` : target.kind);
            row.className = `lm-track-row lm-track-row-${rowKind}`;
            const label = document.createElement('span');
            label.className = 'lm-track-label';
            label.textContent = target.label;
            const lane = document.createElement('div');
            const laneKind = target.kind === 'audioEmpty' ? 'audio-empty' : (target.kind === 'audio' ? `audio ${kindClass === 'voice' ? 'voice' : 'music'}` : target.kind);
            lane.className = `lm-track-lane lm-track-lane-${laneKind}`;
            lane.dataset.target = target.key;
            lane.tabIndex = 0;
            lane.setAttribute('role', 'group');
            lane.setAttribute('aria-label', target.kind === 'audioEmpty'
                ? 'ترک‌های صوتی خالی؛ برای افزودن موسیقی یا گفتار دکمه‌ها را انتخاب کنید یا فایل را اینجا رها کنید'
                : `ترک ${target.label}؛ برای جابه‌جایی نشانگر بکشید`);

            let clipStart = 0;
            let clipEnd = duration;
            let audioTrack = null;
            if (target.kind === 'logo') {
                clipStart = clamp(Number(state.logoEntryTime) || 0, 0, duration);
                clipEnd = clamp(Number(state.logoExitTime) || duration, clipStart, duration);
            } else if (target.kind === 'logoLayer') {
                const layer = state.logoLayers.find(item => item.id === target.layerId);
                if (layer) {
                    clipStart = clamp(Number(layer.entryTime) || 0, 0, duration);
                    clipEnd = clamp(Number(layer.exitTime) || duration, clipStart, duration);
                }
            } else if (target.kind === 'text') {
                const style = state.textStyles[target.key] || TEXT_ITEMS[0].defaults;
                clipStart = clamp(Number(style.entryTime) || 0, 0, duration);
                clipEnd = style.exitAuto === false ? duration : clamp(Number(style.exitTime) || duration, clipStart, duration);
            } else if (target.kind === 'audio') {
                audioTrack = state.audioTracks.find(track => track.id === target.trackId) || null;
            }

            if (target.kind === 'audioEmpty') {
                const emptyAction = document.createElement('div');
                emptyAction.className = 'lm-audio-empty-actions';
                [
                    { kind: 'music', text: '＋ موسیقی' },
                    { kind: 'voice', text: '＋ گفتار' }
                ].forEach(item => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = `lm-audio-empty-cta${item.kind === 'voice' ? ' is-voice' : ''}`;
                    button.textContent = item.text;
                    button.addEventListener('click', event => {
                        event.stopPropagation();
                        chooseAudioFile(item.kind);
                    });
                    emptyAction.appendChild(button);
                });
                lane.appendChild(emptyAction);
            } else if (target.kind === 'audio' && audioTrack) {
                const clip = document.createElement('span');
                clip.className = `lm-track-clip lm-track-clip-audio lm-audio-clip${audioTrack.kind === 'voice' ? ' is-voice' : ''}`;
                clip.tabIndex = 0;
                clip.setAttribute('role', 'slider');
                clip.setAttribute('aria-label', `موقعیت شروع ${audioTrack.kind === 'voice' ? 'گفتار' : 'موسیقی'} ${audioTrack.name}؛ با کلیدهای جهت‌دار جابه‌جا کنید`);
                clip.setAttribute('aria-valuemin', '0');
                clip.setAttribute('aria-valuemax', String(duration));
                clip.title = `${audioTrack.name} · شروع در ${secondsLabel(audioTrack.start)}${audioTrack.loop ? ' · تکرار تا پایان کلیپ' : ''}`;
                placeAudioClip(clip, audioTrack);
                if (audioTrack.peaks.length) {
                    const waveform = document.createElement('span');
                    waveform.className = 'lm-audio-clip-waveform';
                    waveform.setAttribute('aria-hidden', 'true');
                    const bars = Math.min(88, audioTrack.peaks.length);
                    for (let index = 0; index < bars; index += 1) {
                        const bar = document.createElement('i');
                        const peak = audioTrack.peaks[Math.floor(index * audioTrack.peaks.length / bars)] || 0;
                        bar.style.height = `${Math.max(10, Math.round(peak * 86))}%`;
                        waveform.appendChild(bar);
                    }
                    clip.appendChild(waveform);
                }
                const clipName = document.createElement('span');
                clipName.className = 'lm-audio-clip-name';
                clipName.textContent = `${audioTrack.kind === 'voice' ? '◖' : '♫'} ${audioTrack.name} · ${audioTrack.duration ? secondsLabel(audioTrack.duration) : 'در حال بارگذاری'}`;
                clip.appendChild(clipName);
                lane.appendChild(clip);

                clip.addEventListener('pointerdown', event => {
                    if (event.button !== 0 || state.exporting || state.exportPreparing) return;
                    event.stopPropagation();
                    event.preventDefault();
                    const rect = lane.getBoundingClientRect();
                    state.timelinePointerDrag = { type: 'audioClip', pointerId: event.pointerId, clip, lane, trackId: audioTrack.id, startX: event.clientX, startTime: audioTrack.start, laneWidth: Math.max(1, rect.width), moved: false };
                    clip.classList.add('is-dragging');
                    clip.focus({ preventScroll: true });
                    if (clip.setPointerCapture) clip.setPointerCapture(event.pointerId);
                });
                clip.addEventListener('pointermove', event => {
                    const drag = state.timelinePointerDrag;
                    if (!drag || drag.type !== 'audioClip' || drag.pointerId !== event.pointerId) return;
                    const track = state.audioTracks.find(item => item.id === drag.trackId);
                    if (!track) return;
                    const delta = (event.clientX - drag.startX) / drag.laneWidth * duration;
                    if (Math.abs(event.clientX - drag.startX) > 2) drag.moved = true;
                    setTrackStart(track, drag.startTime + delta, false);
                    placeAudioClip(clip, track);
                });
                const finishAudioDrag = (event, cancelled) => {
                    const drag = state.timelinePointerDrag;
                    if (!drag || drag.type !== 'audioClip' || drag.pointerId !== event.pointerId) return;
                    state.timelinePointerDrag = null;
                    clip.classList.remove('is-dragging');
                    const track = state.audioTracks.find(item => item.id === drag.trackId);
                    if (track && drag.moved) {
                        setTrackStart(track, track.start, true);
                        renderAudioTrackList();
                        renderTimelineTracks();
                    } else if (!cancelled) {
                        state.playing = false;
                        seekToTime(timeAt(event.clientX, lane));
                    }
                    if (state.timelineRenderPending) renderTimelineTracks();
                };
                clip.addEventListener('pointerup', event => finishAudioDrag(event, false));
                clip.addEventListener('pointercancel', event => finishAudioDrag(event, true));
                clip.addEventListener('keydown', event => {
                    if (state.exporting || state.exportPreparing || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                    event.preventDefault();
                    event.stopPropagation();
                    const step = event.shiftKey ? 1 : .1;
                    const next = event.key === 'Home' ? 0 : event.key === 'End' ? duration : audioTrack.start + (event.key === 'ArrowRight' ? step : -step);
                    setTrackStart(audioTrack, next, true);
                    placeAudioClip(clip, audioTrack);
                    clip.title = `${audioTrack.name} · شروع در ${secondsLabel(audioTrack.start)}${audioTrack.loop ? ' · تکرار تا پایان کلیپ' : ''}`;
                });
            } else {
                const clip = document.createElement('span');
                clip.className = `lm-track-clip lm-track-clip-${target.kind === 'logoLayer' ? 'logo' : target.key}`;
                clip.style.left = `${(clipStart / duration) * 100}%`;
                clip.style.width = `${Math.max(.6, ((clipEnd - clipStart) / duration) * 100)}%`;
                clip.setAttribute('aria-hidden', 'true');
                lane.appendChild(clip);
            }

            state.keyframes.filter(frame => frame.target === target.key).sort((a, b) => a.time - b.time).forEach(frame => {
                const marker = document.createElement('button');
                const property = keyframeDefinition(frame.target, frame.property);
                marker.type = 'button';
                marker.className = 'lm-keyframe-marker' + (frame.id === state.selectedKeyframeId ? ' is-selected' : '');
                marker.dataset.keyframeId = frame.id;
                marker.style.left = `${(frame.time / duration) * 100}%`;
                marker.textContent = '◆';
                marker.setAttribute('aria-label', `${target.label}، ${property ? property.label : frame.property}، ${secondsLabel(frame.time)}؛ بکشید یا با کلیدهای جهت‌دار جابه‌جا کنید`);
                marker.title = marker.getAttribute('aria-label');
                marker.addEventListener('pointerdown', event => {
                    if (event.button !== 0 || state.exporting || state.exportPreparing) return;
                    event.stopPropagation();
                    const rect = lane.getBoundingClientRect();
                    state.timelinePointerDrag = { type: 'keyframe', pointerId: event.pointerId, marker, frame, startX: event.clientX, startTime: frame.time, laneWidth: Math.max(1, rect.width), moved: false };
                    marker.classList.add('is-dragging');
                    marker.focus({ preventScroll: true });
                    if (marker.setPointerCapture) marker.setPointerCapture(event.pointerId);
                });
                marker.addEventListener('pointermove', event => {
                    const drag = state.timelinePointerDrag;
                    if (!drag || drag.type !== 'keyframe' || drag.pointerId !== event.pointerId) return;
                    if (Math.abs(event.clientX - drag.startX) > 2) drag.moved = true;
                    if (!drag.moved) return;
                    frame.time = Math.round(clamp(drag.startTime + (event.clientX - drag.startX) / drag.laneWidth * duration, 0, duration) * 100) / 100;
                    marker.style.left = `${(frame.time / duration) * 100}%`;
                    marker.setAttribute('aria-label', `${target.label}، ${property ? property.label : frame.property}، ${secondsLabel(frame.time)}؛ بکشید یا با کلیدهای جهت‌دار جابه‌جا کنید`);
                    marker.title = marker.getAttribute('aria-label');
                    if (state.selectedKeyframeId === frame.id && el.keyframeTimeLabel) el.keyframeTimeLabel.textContent = `موقعیت: ${secondsLabel(frame.time)}`;
                });
                const finishKeyframeDrag = (event, cancelled) => {
                    const drag = state.timelinePointerDrag;
                    if (!drag || drag.type !== 'keyframe' || drag.pointerId !== event.pointerId) return;
                    state.timelinePointerDrag = null;
                    marker.classList.remove('is-dragging');
                    if (drag.moved) {
                        frame.time = Math.round(frame.time * 100) / 100;
                        state.selectedKeyframeId = frame.id;
                        selectKeyframe(frame.id, false);
                    } else if (cancelled) marker.blur();
                    if (state.timelineRenderPending && (drag.moved || cancelled)) renderTimelineTracks();
                };
                marker.addEventListener('pointerup', event => finishKeyframeDrag(event, false));
                marker.addEventListener('pointercancel', event => finishKeyframeDrag(event, true));
                marker.addEventListener('keydown', event => {
                    if (state.exporting || state.exportPreparing) return;
                    if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
                        event.preventDefault();
                        event.stopPropagation();
                        const step = event.shiftKey ? 1 : .1;
                        const next = event.key === 'Home' ? 0 : event.key === 'End' ? duration : frame.time + (event.key === 'ArrowRight' ? step : -step);
                        frame.time = Math.round(clamp(next, 0, duration) * 100) / 100;
                        state.selectedKeyframeId = frame.id;
                        selectKeyframe(frame.id, false);
                        const replacement = Array.from(el.timelineTracks.querySelectorAll('.lm-keyframe-marker')).find(item => item.dataset.keyframeId === frame.id);
                        if (replacement) replacement.focus({ preventScroll: true });
                    } else if (event.key === 'Delete' || event.key === 'Backspace') {
                        event.preventDefault();
                        state.selectedKeyframeId = frame.id;
                        deleteSelectedKeyframe();
                    }
                });
                marker.addEventListener('click', event => {
                    event.stopPropagation();
                    selectKeyframe(frame.id, true);
                });
                lane.appendChild(marker);
            });

            if (target.kind === 'audio' || target.kind === 'audioEmpty') {
                lane.addEventListener('dragenter', event => {
                    event.preventDefault();
                    lane.classList.add('is-dragover');
                });
                lane.addEventListener('dragover', event => {
                    event.preventDefault();
                    if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
                    lane.classList.add('is-dragover');
                });
                lane.addEventListener('dragleave', event => {
                    if (!lane.contains(event.relatedTarget)) lane.classList.remove('is-dragover');
                });
                lane.addEventListener('drop', event => {
                    event.preventDefault();
                    lane.classList.remove('is-dragover');
                    const file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0];
                    const kind = audioTrack ? audioTrack.kind : 'music';
                    if (file) addAudioFiles([file], kind);
                });
            }

            lane.addEventListener('pointerdown', event => {
                if (event.button !== 0 || state.exporting || state.exportPreparing || target.kind === 'audioEmpty') return;
                if (event.target.closest('button,.lm-audio-clip,.lm-keyframe-marker')) return;
                state.playing = false;
                state.timelinePointerDrag = { type: 'scrub', pointerId: event.pointerId, lane, moved: false };
                lane.classList.add('is-scrubbing');
                if (lane.setPointerCapture) lane.setPointerCapture(event.pointerId);
                seekToTime(timeAt(event.clientX, lane));
            });
            lane.addEventListener('pointermove', event => {
                const drag = state.timelinePointerDrag;
                if (!drag || drag.type !== 'scrub' || drag.pointerId !== event.pointerId || drag.lane !== lane) return;
                drag.moved = true;
                seekToTime(timeAt(event.clientX, lane));
            });
            const finishLaneScrub = event => {
                const drag = state.timelinePointerDrag;
                if (!drag || drag.type !== 'scrub' || drag.pointerId !== event.pointerId || drag.lane !== lane) return;
                state.timelinePointerDrag = null;
                lane.classList.remove('is-scrubbing');
                if (state.timelineRenderPending) renderTimelineTracks();
            };
            lane.addEventListener('pointerup', finishLaneScrub);
            lane.addEventListener('pointercancel', finishLaneScrub);
            lane.addEventListener('click', event => {
                if (event.target.closest('button,.lm-audio-clip,.lm-keyframe-marker')) return;
                seekToTime(timeAt(event.clientX, lane));
            });
            lane.addEventListener('keydown', event => {
                if (target.kind === 'audioEmpty' && event.key === 'Enter') {
                    event.preventDefault();
                    chooseAudioFile('music');
                    return;
                }
                if (event.key === 'Enter') {
                    event.preventDefault();
                    seekToTime(duration / 2);
                } else if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                    event.preventDefault();
                    seekToTime(currentTime(performance.now()) + (event.key === 'ArrowRight' ? 1 : -1) * (event.shiftKey ? 1 : .1));
                } else if (event.key === 'Home' || event.key === 'End') {
                    event.preventDefault();
                    seekToTime(event.key === 'Home' ? 0 : duration);
                }
            });
            row.append(label, lane);
            el.timelineTracks.appendChild(row);
        });
        renderKeyframeList();
        updateTimelinePlayhead(currentTime(performance.now()));
    }

    function updateTimelinePlayhead(time) {
        const safeTime = clamp(time, 0, state.duration);
        const position = `${(safeTime / Math.max(.1, state.duration)) * 100}%`;
        if (el.keyframeTimeLabel) el.keyframeTimeLabel.textContent = `موقعیت: ${secondsLabel(safeTime)}`;
        if (el.timelineRuler) el.timelineRuler.style.setProperty('--playhead', position);
        if (el.timelineTracks) el.timelineTracks.querySelectorAll('.lm-track-lane').forEach(lane => lane.style.setProperty('--playhead', position));
    }

    function updateKeyframePropertyOptions() {
        if (!el.keyframeTarget || !el.keyframeProperty) return;
        const previous = el.keyframeProperty.value;
        const definitions = keyframeDefinitionsForTarget(el.keyframeTarget.value);
        el.keyframeProperty.replaceChildren();
        definitions.forEach(definition => {
            const option = document.createElement('option');
            option.value = definition.key;
            option.textContent = definition.label;
            el.keyframeProperty.appendChild(option);
        });
        if (definitions.some(item => item.key === previous)) el.keyframeProperty.value = previous;
        else if (definitions.length) el.keyframeProperty.value = definitions[0].key;
        syncKeyframeValueControl();
    }

    function formatKeyframeValue(value, definition) {
        return `${Number(value).toLocaleString('fa-IR', { maximumFractionDigits: 1 })}${definition ? definition.unit : ''}`;
    }

    function syncKeyframeValueControl() {
        if (!el.keyframeValue || !el.keyframeProperty) return;
        const target = el.keyframeTarget.value;
        const property = el.keyframeProperty.value;
        const definition = keyframeDefinition(target, property);
        if (!definition) return;
        const selected = state.keyframes.find(frame => frame.id === state.selectedKeyframeId && frame.target === target && frame.property === property);
        const time = currentTime(performance.now());
        const value = selected ? selected.value : evaluateKeyframes(target, property, time, getKeyframeBaseValue(target, property));
        el.keyframeValue.min = String(definition.min);
        el.keyframeValue.max = String(definition.max);
        el.keyframeValue.step = String(definition.step);
        el.keyframeValue.value = String(clamp(value, definition.min, definition.max));
        el.keyframeValueName.textContent = definition.label;
        el.keyframeValueLabel.textContent = formatKeyframeValue(value, definition);
        el.keyframeTimeLabel.textContent = `موقعیت: ${secondsLabel(time)}`;
        el.deleteKeyframe.disabled = !selected;
        if (selected) el.keyframeEasing.value = selected.easing || 'cinematic';
    }

    function selectKeyframe(id, seek) {
        const frame = state.keyframes.find(item => item.id === id);
        if (!frame) return;
        state.selectedKeyframeId = frame.id;
        el.keyframeTarget.value = frame.target;
        updateKeyframePropertyOptions();
        el.keyframeProperty.value = frame.property;
        el.keyframeEasing.value = frame.easing || 'cinematic';
        syncKeyframeValueControl();
        renderTimelineTracks();
        if (seek) seekToTime(frame.time);
    }

    function seekToTime(value) {
        if (state.exporting || state.exportPreparing) return;
        const time = clamp(Number(value) || 0, 0, state.duration);
        state.offset = time;
        state.startedAt = performance.now();
        state.audioLastSync = 0;
        updateTransport(time);
        updateTimelinePlayhead(time);
        syncKeyframeValueControl();
        drawFrame(time);
        syncAudioPlayback(time, true);
    }

    function addKeyframeAtPlayhead() {
        const target = el.keyframeTarget.value;
        const property = el.keyframeProperty.value;
        const definition = keyframeDefinition(target, property);
        if (!definition) return;
        const time = Math.round(currentTime(performance.now()) * 100) / 100;
        const value = clamp(Number(el.keyframeValue.value), definition.min, definition.max);
        const easing = EASING_OPTIONS.some(option => option[0] === el.keyframeEasing.value) ? el.keyframeEasing.value : 'cinematic';
        let frame = state.keyframes.find(item => item.target === target && item.property === property && Math.abs(item.time - time) < .04);
        if (frame) {
            frame.time = time;
            frame.value = value;
            frame.easing = easing;
        } else {
            state.keyframeSequence += 1;
            frame = { id: `kf-${Date.now().toString(36)}-${state.keyframeSequence.toString(36)}`, target, property, time, value, easing };
            state.keyframes.push(frame);
        }
        state.selectedKeyframeId = frame.id;
        renderTimelineTracks();
        syncKeyframeValueControl();
        drawFrame(time);
        showToast('کی‌فریم در نشانگر زمان ثبت شد.');
    }

    function updateSelectedKeyframeValue(value) {
        const target = el.keyframeTarget.value;
        const property = el.keyframeProperty.value;
        const definition = keyframeDefinition(target, property);
        if (!definition) return;
        const amount = clamp(Number(value), definition.min, definition.max);
        const selected = state.keyframes.find(frame => frame.id === state.selectedKeyframeId && frame.target === target && frame.property === property);
        el.keyframeValueLabel.textContent = formatKeyframeValue(amount, definition);
        if (selected) {
            selected.value = amount;
            renderTimelineTracks();
            const time = currentTime(performance.now());
            drawFrame(time);
            syncAudioPlayback(time, true);
        }
    }

    function deleteSelectedKeyframe() {
        if (!state.selectedKeyframeId) return;
        state.keyframes = state.keyframes.filter(frame => frame.id !== state.selectedKeyframeId);
        state.selectedKeyframeId = null;
        renderTimelineTracks();
        syncKeyframeValueControl();
        const time = currentTime(performance.now());
        drawFrame(time);
        syncAudioPlayback(time, true);
    }

    function renderTimelineTracksForDurationChange() {
        state.keyframes = state.keyframes.map(frame => ({ ...frame, time: clamp(frame.time, 0, state.duration) }));
        renderTimelineTracks();
        syncKeyframeValueControl();
    }

    function frameLoop(now) {
        const time = currentTime(now);
        if (!state.exporting) {
            drawFrame(time);
        } else if (el.renderProgress && state.exportTotalFrames > 0) {
            const percent = Math.round(Math.min(1, state.exportFrameIndex / state.exportTotalFrames) * 100);
            el.renderProgress.textContent = `${percent.toLocaleString('fa-IR')}٪ · ${state.exportFrameIndex.toLocaleString('fa-IR')} از ${state.exportTotalFrames.toLocaleString('fa-IR')} فریم`;
        }
        updateTransport(time);
        updateTimelinePlayhead(time);
        syncAudioPlayback(time, false);
        window.requestAnimationFrame(frameLoop);
    }

    function showToast(message, type) {
        window.clearTimeout(state.toastTimer);
        el.toast.textContent = message;
        el.toast.className = 'lm-toast' + (type ? ` is-${type}` : '');
        el.toast.hidden = false;
        state.toastTimer = window.setTimeout(() => { el.toast.hidden = true; }, 3600);
    }

    function renderCategoryChips(container, categories, selected, onSelect) {
        container.replaceChildren();
        const all = ['همه', ...categories];
        all.forEach(category => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'lm-category-chip' + (selected === category ? ' is-active' : '');
            button.textContent = category;
            button.dataset.category = category;
            button.setAttribute('aria-pressed', selected === category ? 'true' : 'false');
            button.addEventListener('click', () => onSelect(category));
            container.appendChild(button);
        });
    }

    function renderMotionStyles() {
        const query = (el.motionSearch.value || '').trim().toLocaleLowerCase('fa');
        const visible = MOTION_STYLES.filter(style => {
            const categoryMatch = state.motionCategory === 'همه' || style.category === state.motionCategory;
            const searchMatch = !query || `${style.name} ${style.category} ${style.description}`.toLocaleLowerCase('fa').includes(query);
            return categoryMatch && searchMatch;
        });
        el.motionStyles.replaceChildren();
        el.motionCount.textContent = `${visible.length.toLocaleString('fa-IR')} / ${MOTION_STYLES.length.toLocaleString('fa-IR')} سبک`;
        if (!visible.length) {
            const empty = document.createElement('div');
            empty.className = 'lm-picker-empty';
            empty.textContent = 'سبکی با این نام پیدا نشد.';
            el.motionStyles.appendChild(empty);
            return;
        }
        visible.forEach(style => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'lm-style-option' + (state.motionStyle.id === style.id ? ' is-selected' : '');
            button.setAttribute('role', 'option');
            button.setAttribute('aria-selected', state.motionStyle.id === style.id ? 'true' : 'false');
            button.title = `${style.name} — ${style.description}`;
            const number = document.createElement('span');
            number.className = 'lm-style-num';
            number.textContent = String(MOTION_STYLES.indexOf(style) + 1).padStart(2, '0');
            const icon = document.createElement('span');
            icon.className = 'lm-style-icon';
            icon.dataset.family = style.family;
            icon.setAttribute('aria-hidden', 'true');
            const copy = document.createElement('span');
            copy.className = 'lm-style-copy';
            const name = document.createElement('strong');
            name.textContent = style.name;
            const desc = document.createElement('small');
            desc.textContent = style.description;
            copy.append(name, desc);
            button.append(number, icon, copy);
            button.addEventListener('click', () => {
                state.motionStyle = style;
                el.selectedMotion.textContent = style.name;
                renderMotionStyles();
            });
            el.motionStyles.appendChild(button);
        });
    }

    function renderBackgroundAnimations() {
        const query = (el.backgroundSearch.value || '').trim().toLocaleLowerCase('fa');
        const visible = BACKGROUND_ANIMATIONS.filter(animation => {
            const categoryMatch = state.backgroundCategory === 'همه' || animation.category === state.backgroundCategory;
            const searchMatch = !query || `${animation.name} ${animation.category}`.toLocaleLowerCase('fa').includes(query);
            return categoryMatch && searchMatch;
        });
        el.backgroundGrid.replaceChildren();
        el.backgroundCount.textContent = `${visible.length.toLocaleString('fa-IR')} / ${BACKGROUND_ANIMATIONS.length.toLocaleString('fa-IR')}`;
        if (!visible.length) {
            const empty = document.createElement('div');
            empty.className = 'lm-picker-empty';
            empty.textContent = 'حرکت زمینه‌ای پیدا نشد.';
            el.backgroundGrid.appendChild(empty);
            return;
        }
        visible.forEach(animation => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'lm-bg-option' + (state.backgroundAnimation.id === animation.id ? ' is-selected' : '');
            button.setAttribute('role', 'option');
            button.setAttribute('aria-selected', state.backgroundAnimation.id === animation.id ? 'true' : 'false');
            button.title = animation.name;
            const icon = document.createElement('span');
            icon.className = 'lm-bg-icon';
            icon.dataset.family = animation.family;
            icon.setAttribute('aria-hidden', 'true');
            const name = document.createElement('span');
            name.className = 'lm-bg-copy';
            name.textContent = animation.name;
            button.append(icon, name);
            button.addEventListener('click', () => {
                state.backgroundAnimation = animation;
                document.getElementById('selectedBackgroundName').textContent = animation.name;
                renderBackgroundAnimations();
            });
            el.backgroundGrid.appendChild(button);
        });
    }

    function renderPalettes() {
        el.palette.replaceChildren();
        COLOR_PALETTES.forEach((palette, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'lm-swatch' + (index === 0 ? ' is-selected' : '');
            button.title = palette.name;
            button.setAttribute('aria-label', palette.name);
            button.setAttribute('aria-pressed', index === 0 ? 'true' : 'false');
            const strip = document.createElement('i');
            strip.style.setProperty('--swatch-a', palette.accent);
            strip.style.setProperty('--swatch-b', palette.gold);
            const label = document.createElement('span');
            label.textContent = palette.name;
            button.append(strip, label);
            button.addEventListener('click', () => {
                document.querySelectorAll('.lm-swatch').forEach(item => {
                    const selected = item === button;
                    item.classList.toggle('is-selected', selected);
                    item.setAttribute('aria-pressed', selected ? 'true' : 'false');
                });
                state.accent = palette.accent;
                state.gold = palette.gold;
                el.accentColor.value = palette.accent;
                el.goldColor.value = palette.gold;
            });
            el.palette.appendChild(button);
        });
    }

    function renderBackgroundPalettes() {
        el.backgroundPalettes.replaceChildren();
        BACKGROUND_COLORS.forEach((palette, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'lm-bg-swatch' + (index === 0 ? ' is-selected' : '');
            button.style.background = `linear-gradient(145deg,${mixColor(palette.value, '#ffffff', .1)},${palette.value})`;
            button.title = palette.name;
            button.setAttribute('aria-label', `زمینه ${palette.name}`);
            button.setAttribute('aria-pressed', index === 0 ? 'true' : 'false');
            const label = document.createElement('span');
            label.textContent = palette.name;
            button.appendChild(label);
            button.addEventListener('click', () => {
                document.querySelectorAll('.lm-bg-swatch').forEach(item => {
                    const selected = item === button;
                    item.classList.toggle('is-selected', selected);
                    item.setAttribute('aria-pressed', selected ? 'true' : 'false');
                });
                state.background = palette.value;
                el.backgroundColor.value = palette.value;
            });
            el.backgroundPalettes.appendChild(button);
        });
    }

    const MOTION_CATEGORIES = [...new Set(MOTION_STYLES.map(style => style.category))];
    const BACKGROUND_CATEGORIES = [...new Set(BACKGROUND_ANIMATIONS.map(animation => animation.category))];

    function selectMotionCategory(category) {
        state.motionCategory = category;
        renderCategoryChips(el.motionCategories, MOTION_CATEGORIES, state.motionCategory, selectMotionCategory);
        renderMotionStyles();
    }

    function selectBackgroundCategory(category) {
        state.backgroundCategory = category;
        renderCategoryChips(el.backgroundCategories, BACKGROUND_CATEGORIES, state.backgroundCategory, selectBackgroundCategory);
        renderBackgroundAnimations();
    }

    function updateCategories() {
        renderCategoryChips(el.motionCategories, MOTION_CATEGORIES, state.motionCategory, selectMotionCategory);
        renderCategoryChips(el.backgroundCategories, BACKGROUND_CATEGORIES, state.backgroundCategory, selectBackgroundCategory);
    }

    function getSvgExtension(name) { return /\.svg$/i.test(name || ''); }

    function hasUnsafeSvgUrl(value) {
        const urlPattern = /url\(\s*([^)]*)\s*\)/gi;
        let match;
        while ((match = urlPattern.exec(String(value || ''))) !== null) {
            const reference = match[1].trim().replace(/^(["'])(.*)\1$/, '$2').trim();
            if (!reference.startsWith('#')) return true;
        }
        return false;
    }

    function sanitizeSvg(source) {
        const parser = new DOMParser();
        const doc = parser.parseFromString(source, 'image/svg+xml');
        if (doc.querySelector('parsererror') || !doc.documentElement || doc.documentElement.localName !== 'svg') throw new Error('فایل SVG معتبر نیست.');
        Array.from(doc.documentElement.querySelectorAll('*')).forEach(node => {
            const tag = (node.localName || '').toLowerCase();
            if (['script', 'foreignobject', 'iframe', 'object', 'embed', 'audio', 'video', 'image', 'feimage'].includes(tag)) node.remove();
        });
        doc.querySelectorAll('style').forEach(node => {
            if (/@import\b/i.test(node.textContent || '') || hasUnsafeSvgUrl(node.textContent || '')) node.remove();
        });
        const allNodes = [doc.documentElement, ...doc.documentElement.querySelectorAll('*')];
        allNodes.forEach(node => {
            Array.from(node.attributes || []).forEach(attribute => {
                const name = attribute.name.toLowerCase();
                const value = attribute.value.trim();
                if (name.startsWith('on') || name === 'srcdoc') {
                    node.removeAttribute(attribute.name);
                    return;
                }
                if (name === 'href' || name === 'xlink:href') {
                    if (value && !value.startsWith('#')) node.removeAttribute(attribute.name);
                    return;
                }
                if (/javascript:|data:text\/html/i.test(value) || hasUnsafeSvgUrl(value)) node.removeAttribute(attribute.name);
            });
        });
        return new XMLSerializer().serializeToString(doc.documentElement);
    }

    function setLogoSource(source, fileName, objectUrl) {
        const loadId = ++state.logoLoadId;
        const candidate = new Image();
        candidate.crossOrigin = 'anonymous';
        candidate.onload = function () {
            if (loadId !== state.logoLoadId) {
                if (objectUrl) URL.revokeObjectURL(objectUrl);
                return;
            }
            if (!candidate.naturalWidth || !candidate.naturalHeight) {
                if (objectUrl) URL.revokeObjectURL(objectUrl);
                showToast('این فایل تصویری قابل خواندن نیست.', 'error');
                return;
            }
            if (state.objectUrl) URL.revokeObjectURL(state.objectUrl);
            state.objectUrl = objectUrl || null;
            state.logo = candidate;
            el.thumb.src = source;
            el.fileName.textContent = fileName;
        };
        candidate.onerror = function () {
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            if (loadId === state.logoLoadId) showToast('بارگذاری لوگو انجام نشد؛ فرمت فایل را بررسی کنید.', 'error');
        };
        candidate.src = source;
    }

    async function createCleanLogoForExport() {
        const source = state.objectUrl || DEFAULT_LOGO;
        let response;
        try { response = await fetch(source, { cache: 'no-store' }); }
        catch (error) { throw new Error('لوگو از مبدأ امن خوانده نشد؛ یک فایل لوگوی محلی بارگذاری کنید یا لوگوی پیش‌فرض را بازنشانی کنید.'); }
        if (!response.ok) throw new Error(`لوگوی فعلی از سرور خوانده نشد (HTTP ${response.status}).`);
        let blob = await response.blob();
        if (!blob.size || blob.size > MAX_LOGO_BYTES) throw new Error('حجم لوگو برای خروجی معتبر نیست.');
        if (blob.type === 'image/svg+xml' || getSvgExtension(source)) {
            blob = new Blob([sanitizeSvg(await blob.text())], { type: 'image/svg+xml' });
        }
        const objectUrl = URL.createObjectURL(blob);
        const image = new Image();
        try {
            await new Promise((resolve, reject) => {
                image.onload = resolve;
                image.onerror = () => reject(new Error('نسخه‌ی امن لوگو در مرورگر خوانده نشد.'));
                image.src = objectUrl;
            });
            if (!image.naturalWidth || !image.naturalHeight) throw new Error('ابعاد لوگو برای خروجی معتبر نیست.');
            return { image, objectUrl };
        } catch (error) {
            URL.revokeObjectURL(objectUrl);
            throw error;
        }
    }

    function assertCanvasOriginClean() {
        try { canvasContext.getImageData(0, 0, 1, 1); }
        catch (error) {
            throw new Error('پیش‌نمایش هنوز تصویر خارج از دامنه دارد؛ لوگوی محلی را دوباره بارگذاری یا بازنشانی کنید.');
        }
    }

    async function handleLogoFile(file) {
        if (!file) return;
        if (file.size > MAX_LOGO_BYTES) { showToast('حجم لوگو نباید بیشتر از ۸ مگابایت باشد.', 'warning'); return; }
        if (file.size === 0) { showToast('فایل انتخاب‌شده خالی است.', 'warning'); return; }
        const extensionIsSvg = getSvgExtension(file.name);
        const isSvg = file.type === 'image/svg+xml' || extensionIsSvg;
        const allowedTypes = ['image/png', 'image/jpeg', 'image/webp'];
        if (!isSvg && file.type && !allowedTypes.includes(file.type)) { showToast('لطفاً فایل PNG، JPG، WebP یا SVG انتخاب کنید.', 'warning'); return; }
        try {
            if (isSvg) {
                const source = sanitizeSvg(await file.text());
                const objectUrl = URL.createObjectURL(new Blob([source], { type: 'image/svg+xml' }));
                setLogoSource(objectUrl, file.name, objectUrl);
            } else {
                const objectUrl = URL.createObjectURL(file);
                setLogoSource(objectUrl, file.name, objectUrl);
            }
            showToast('لوگوی جدید برای پیش‌نمایش آماده شد.');
        } catch (error) {
            showToast(error && error.message ? error.message : 'خواندن لوگو انجام نشد.', 'error');
        }
    }

    function lockEditor(locked) {
        document.querySelectorAll('.lm-controls button,.lm-controls input,.lm-controls textarea,.lm-controls select,.lm-timeline-editor button,.lm-timeline-editor input,.lm-timeline-editor select,#playBtn,#restartBtn,#timelineRange,#downloadFrame').forEach(control => { control.disabled = locked; });
        el.export.disabled = locked;
        el.frame.disabled = locked;
        el.overlay.hidden = !locked;
        el.exportLabel.textContent = locked ? 'در حال آماده‌سازی…' : 'دریافت ویدئو';
    }

    function isRecorderTypeSupported(type) {
        if (!window.MediaRecorder || typeof MediaRecorder.isTypeSupported !== 'function') return false;
        try { return MediaRecorder.isTypeSupported(type); } catch (error) { return false; }
    }

    function recorderCandidates(format, codec) {
        if (format === 'mp4') {
            return ['video/mp4;codecs=avc1.640028', 'video/mp4;codecs=avc1.42E01E', 'video/mp4;codecs=h264', 'video/mp4'];
        }
        if (codec === 'vp9') return ['video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm'];
        if (codec === 'vp8') return ['video/webm;codecs=vp8', 'video/webm;codecs=vp9', 'video/webm'];
        return ['video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm'];
    }

    function supportedRecorderType(format, codec) {
        return recorderCandidates(format, codec).find(isRecorderTypeSupported) || '';
    }

    function syncCodecOptions() {
        const vp9 = el.codec.querySelector('option[value="vp9"]');
        const vp8 = el.codec.querySelector('option[value="vp8"]');
        const h264 = el.codec.querySelector('option[value="h264"]');
        if (vp9) vp9.disabled = state.format !== 'webm' || !isRecorderTypeSupported('video/webm;codecs=vp9');
        if (vp8) vp8.disabled = state.format !== 'webm' || !isRecorderTypeSupported('video/webm;codecs=vp8');
        if (h264) h264.disabled = state.format !== 'mp4' || !supportedRecorderType('mp4', 'h264');
        const selected = el.codec.selectedOptions[0];
        if (selected && selected.disabled) {
            el.codec.value = 'auto';
            state.codec = 'auto';
        }
    }

    function configureFormatOptions() {
        const webmOption = el.format.querySelector('option[value="webm"]');
        const mp4Option = el.format.querySelector('option[value="mp4"]');
        const canCaptureCanvas = typeof canvas.captureStream === 'function';
        const webmSupported = canCaptureCanvas && !!supportedRecorderType('webm', 'auto');
        const mp4Supported = canCaptureCanvas && !!supportedRecorderType('mp4', 'auto');
        if (webmOption) webmOption.disabled = !webmSupported;
        if (mp4Option) mp4Option.disabled = !mp4Supported;
        if (state.format === 'mp4' && !mp4Supported) state.format = webmSupported ? 'webm' : 'mp4';
        if (state.format === 'webm' && !webmSupported && mp4Supported) state.format = 'mp4';
        el.format.value = state.format;
        syncCodecOptions();
        if (el.support) {
            if (webmSupported && mp4Supported) el.support.textContent = 'WebM و MP4 در این مرورگر پشتیبانی می‌شوند؛ فرمت انتخابی روی فایل دانلودشده اعمال می‌شود.';
            else if (mp4Supported) el.support.textContent = 'این مرورگر خروجی MP4 (H.264) را پشتیبانی می‌کند؛ فرمت WebM در دسترس نیست.';
            else if (webmSupported) el.support.textContent = 'این مرورگر خروجی WebM را پشتیبانی می‌کند؛ گزینه‌ی MP4 فقط در مرورگرهای سازگار فعال می‌شود.';
            else el.support.textContent = 'ضبط ویدئو در این مرورگر پشتیبانی نمی‌شود؛ از نسخه‌ی جدید Chrome، Edge یا Firefox استفاده کنید.';
        }
    }

    function chooseRecorderType() {
        if (!window.MediaRecorder || typeof canvas.captureStream !== 'function') return { type: '', fallback: false };
        const preferred = recorderCandidates(state.format, state.codec);
        const type = preferred.find(isRecorderTypeSupported) || '';
        const requested = state.codec === 'vp9' ? 'vp9' : (state.codec === 'vp8' ? 'vp8' : (state.codec === 'h264' ? 'avc1' : ''));
        const fallback = !!type && !!requested && !type.toLowerCase().includes(requested.toLowerCase());
        return { type, fallback, format: state.format };
    }

    function computeBitrate() {
        const base = state.bitrate === 'compact' ? 3.2 : (state.bitrate === 'standard' ? 5.8 : 9.5);
        const pixels = canvas.width * canvas.height;
        const scaled = base * (pixels / (1920 * 1080)) * (state.fps / 30) * 1000000;
        return Math.round(clamp(scaled, 1800000, 45000000));
    }

    function stopTracks() {
        if (state.stream) {
            state.stream.getTracks().forEach(track => track.stop());
            state.stream = null;
        }
    }

    function restoreAfterExport() {
        window.clearTimeout(state.fallbackTimer);
        window.clearTimeout(state.exportTimer);
        state.exporting = false;
        state.exportPreparing = false;
        state.exportStopRequested = false;
        state.exportFrameIndex = 0;
        state.exportTotalFrames = 0;
        state.exportMimeType = '';
        state.frameTrack = null;
        state.manualFrameCapture = false;
        state.recorder = null;
        stopTracks();
        if (state.exportLogoUrl) {
            state.logo = state.exportOriginalLogo;
            URL.revokeObjectURL(state.exportLogoUrl);
        }
        state.exportOriginalLogo = null;
        state.exportLogoUrl = null;
        lockEditor(false);
        if (el.renderProgress) el.renderProgress.textContent = '۰٪ · این پنجره را باز نگه دارید';
        state.playing = state.restorePlaying;
        state.offset = state.restoreOffset;
        state.startedAt = performance.now();
        updateCanvasResolution();
        updateTransport(state.offset);
        state.audioLastSync = 0;
        syncAudioPlayback(state.offset, true);
    }

    function finishExport(error) {
        if (!state.exporting) return;
        const recorder = state.recorder;
        if (error) {
            console.error('Logo-motion recording failed:', error);
            restoreAfterExport();
            const detail = error && error.message ? ` (${String(error.message).slice(0, 120)})` : '';
            showToast(`ضبط ویدئو متوقف شد${detail}؛ فرمت خروجی یا وضوح تصویر را تغییر دهید و دوباره تلاش کنید.`, 'error');
            return;
        }
        const mimeType = recorder && recorder.mimeType ? recorder.mimeType : (state.exportMimeType || 'video/webm');
        const blob = new Blob(state.chunks, { type: mimeType });
        if (!blob.size) {
            restoreAfterExport();
            showToast('فایل ویدئو ساخته نشد؛ مرورگر را به‌روز کنید.', 'error');
            return;
        }
        const extension = /video\/mp4/i.test(mimeType) ? 'mp4' : 'webm';
        const url = URL.createObjectURL(blob);
        const fileName = `sahand-service-logo-motion.${extension}`;
        if (state.lastVideoUrl) URL.revokeObjectURL(state.lastVideoUrl);
        state.lastVideoUrl = url;
        el.videoDownloadLink.href = url;
        el.videoDownloadLink.download = fileName;
        el.videoResultMeta.textContent = `${fileName} · ${(blob.size / 1024 / 1024).toLocaleString('fa-IR', { maximumFractionDigits: 1 })} مگابایت · ${state.fps} fps`;
        el.videoResult.hidden = false;
        try {
            const autoDownload = document.createElement('a');
            autoDownload.href = url;
            autoDownload.download = fileName;
            autoDownload.hidden = true;
            document.body.appendChild(autoDownload);
            autoDownload.click();
            autoDownload.remove();
        } catch (downloadError) {
            // Keep the visible download link available when automatic downloads are blocked.
        }
        restoreAfterExport();
        showToast(`ویدئو آماده است؛ اگر دریافت خودکار شروع نشد، روی «دانلود ویدئو» بزنید.`);
    }

    function createExportStream(fps) {
        if (typeof canvas.captureStream !== 'function') throw new Error('Canvas capture is unavailable');
        state.frameTrack = null;
        state.manualFrameCapture = false;
        const stream = canvas.captureStream(clamp(Number(fps) || 30, 24, 60));
        const videoTrack = stream && stream.getVideoTracks ? stream.getVideoTracks()[0] : null;
        if (!videoTrack || videoTrack.readyState === 'ended') {
            if (stream && stream.getTracks) stream.getTracks().forEach(track => track.stop());
            throw new Error('Canvas stream has no active video track');
        }
        return stream;
    }

    function createMediaRecorder(stream, mimeType) {
        const bitrate = computeBitrate();
        let recorder;
        try {
            recorder = new MediaRecorder(stream, { mimeType, videoBitsPerSecond: bitrate });
        } catch (error) {
            try { recorder = new MediaRecorder(stream, { mimeType }); }
            catch (fallbackError) {
                const combinedError = new Error(`${fallbackError.message || 'MediaRecorder init failed'} (bitrate option also failed: ${error.message || error})`);
                combinedError.cause = fallbackError;
                throw combinedError;
            }
        }
        recorder.ondataavailable = event => { if (event.data && event.data.size > 0) state.chunks.push(event.data); };
        recorder.onerror = event => finishExport(event && event.error ? event.error : new Error('MediaRecorder error'));
        recorder.onstop = () => finishExport(null);
        return recorder;
    }

    function startRecorderWithFallback(stream, preferredType) {
        const candidates = [
            preferredType,
            ...recorderCandidates(state.format, state.codec),
            ...recorderCandidates('webm', 'auto')
        ].filter((type, index, list) => type && list.indexOf(type) === index && isRecorderTypeSupported(type));
        let lastError = null;
        for (const type of candidates) {
            let recorder = null;
            try {
                recorder = createMediaRecorder(stream, type);
                recorder.start(250);
                return { recorder, type };
            } catch (error) {
                lastError = error;
                if (recorder && recorder.state !== 'inactive') {
                    recorder.ondataavailable = null;
                    recorder.onerror = null;
                    recorder.onstop = null;
                    try { recorder.stop(); } catch (stopError) {}
                }
            }
        }
        throw lastError || new Error('No supported MediaRecorder type could start');
    }

    function processExportFrame() {
        if (!state.exporting || state.exportStopRequested) return;
        const frameIndex = state.exportFrameIndex;
        if (frameIndex >= state.exportTotalFrames) {
            state.exportStopRequested = true;
            state.exportTimer = window.setTimeout(() => {
                try { if (state.recorder && state.recorder.state !== 'inactive') state.recorder.stop(); }
                catch (error) { finishExport(error); }
            }, Math.max(1, Math.round(1000 / state.fps)));
            return;
        }
        try {
            const frameTime = Math.min(state.duration, frameIndex / state.fps);
            if (state.audioTracks.length) syncAudioPlayback(frameTime, frameIndex === 0);
            drawFrame(frameTime);
            if (state.manualFrameCapture && state.frameTrack) state.frameTrack.requestFrame();
        } catch (error) {
            finishExport(error);
            return;
        }
        state.exportFrameIndex += 1;
        const frameDelay = Math.max(1, Math.round(1000 / state.fps));
        if (state.exportFrameIndex >= state.exportTotalFrames) {
            state.exportStopRequested = true;
            state.exportTimer = window.setTimeout(() => {
                try { if (state.recorder && state.recorder.state !== 'inactive') state.recorder.stop(); }
                catch (error) { finishExport(error); }
            }, frameDelay);
        } else {
            state.exportTimer = window.setTimeout(processExportFrame, frameDelay);
        }
    }

    async function startExport() {
        if (state.exporting || state.exportPreparing) return;
        const recorderChoice = chooseRecorderType();
        if (!recorderChoice.type) {
            const name = state.format === 'mp4' ? 'MP4' : 'WebM';
            showToast(`خروجی ${name} در این مرورگر پشتیبانی نمی‌شود. فرمت سازگار دیگری را انتخاب کنید.`, 'warning');
            return;
        }
        try {
            state.restorePlaying = state.playing;
            state.restoreOffset = currentTime(performance.now());
            state.playing = false;
            state.offset = 0;
            state.chunks = [];
            state.exportFrameIndex = 0;
            state.exportTotalFrames = Math.max(1, Math.round(state.duration * state.fps));
            state.exportStopRequested = false;
            updateCanvasResolution();
            lockEditor(true);
            state.exportPreparing = true;
            if (el.renderProgress) el.renderProgress.textContent = 'در حال آماده‌سازی لوگوی امن…';

            const cleanLogo = await createCleanLogoForExport();
            state.exportOriginalLogo = state.logo;
            state.exportLogoUrl = cleanLogo.objectUrl;
            state.logo = cleanLogo.image;
            canvas.width = canvas.width;
            drawFrame(0);
            assertCanvasOriginClean();

            state.exportPreparing = false;
            state.exporting = true;
            state.exportStart = performance.now();
            state.stream = createExportStream(state.fps);
            if (state.audioTracks.length) {
                state.audioTracks.forEach(track => ensureAudioGraph(track));
                syncAudioPlayback(0, true);
                addAudioMixTrack(state.stream);
            }
            if (el.renderProgress) el.renderProgress.textContent = '۰٪ · آماده‌سازی فریم‌ها…';
            const startedRecorder = startRecorderWithFallback(state.stream, recorderChoice.type);
            state.recorder = startedRecorder.recorder;
            state.exportMimeType = startedRecorder.type;
            if (startedRecorder.type !== recorderChoice.type || recorderChoice.fallback) {
                showToast('کدک درخواستی در دسترس نبود؛ از فرمت سازگار جایگزین استفاده می‌شود.', 'warning');
            }
            state.fallbackTimer = window.setTimeout(() => {
                if (state.recorder && state.recorder.state !== 'inactive') {
                    try { state.recorder.stop(); } catch (error) { finishExport(error); }
                }
            }, state.duration * 10000 + 30000);
            state.exportTimer = window.setTimeout(processExportFrame, 0);
        } catch (error) {
            console.error('Unable to initialize logo-motion recording:', error);
            restoreAfterExport();
            const detail = error && error.message ? ` (${String(error.message).slice(0, 120)})` : '';
            showToast(`شروع ضبط ویدئو ممکن نشد${detail}؛ لوگوی محلی یا فرمت WebM را امتحان کنید.`, 'error');
        }
    }

    const MAX_CUSTOM_FONT_BYTES = 8 * 1024 * 1024;
    const MAX_CUSTOM_FONT_LIBRARY_BYTES = 12 * 1024 * 1024;
    const MAX_CUSTOM_FONT_COUNT = 8;
    const MAX_AUDIO_BYTES = 40 * 1024 * 1024;
    const MAX_AUDIO_TRACKS = 12;
    const MAX_PROJECT_BYTES = 100 * 1024 * 1024;

    function fontMimeType(fileName) {
        const extension = String(fileName || '').split('.').pop().toLowerCase();
        return ({ woff2: 'font/woff2', woff: 'font/woff', ttf: 'font/ttf', otf: 'font/otf' })[extension] || '';
    }

    function hashFontBuffer(buffer) {
        const bytes = new Uint8Array(buffer);
        let hash = 2166136261;
        for (let index = 0; index < bytes.length; index++) hash = Math.imul(hash ^ bytes[index], 16777619) >>> 0;
        return hash.toString(16).padStart(8, '0');
    }

    async function registerCustomFont(file, quiet) {
        const mime = fontMimeType(file.name);
        if (!mime) throw new Error(`فرمت فونت ${file.name} پشتیبانی نمی‌شود.`);
        if (!file.size || file.size > MAX_CUSTOM_FONT_BYTES) throw new Error('حجم هر فونت باید کمتر از ۸ مگابایت باشد.');
        const totalSize = state.customFonts.reduce((total, font) => total + font.size, 0);
        if (totalSize + file.size > MAX_CUSTOM_FONT_LIBRARY_BYTES) throw new Error('حجم مجموع فونت‌های شخصی نمی‌تواند از ۱۲ مگابایت بیشتر شود.');
        const buffer = await file.arrayBuffer();
        const family = `SahandCustom_${hashFontBuffer(buffer)}`;
        const existing = state.customFonts.find(font => font.family === family);
        if (existing) return existing;
        if (state.customFonts.length >= MAX_CUSTOM_FONT_COUNT) throw new Error('حداکثر ۸ فونت شخصی می‌توانید اضافه کنید.');
        if (typeof FontFace === 'undefined' || !document.fonts) throw new Error('بارگذاری فونت سفارشی در این مرورگر پشتیبانی نمی‌شود.');
        const blob = new Blob([buffer], { type: mime });
        const objectUrl = URL.createObjectURL(blob);
        const face = new FontFace(family, `url("${objectUrl}")`);
        try {
            await face.load();
            document.fonts.add(face);
        } catch (error) {
            URL.revokeObjectURL(objectUrl);
            throw new Error(`خواندن فونت «${file.name}» انجام نشد؛ فایل را بررسی کنید.`);
        }
        const entry = { family, name: file.name.replace(/\.[^.]+$/, ''), fileName: file.name, file, size: file.size, objectUrl, face };
        state.customFonts.push(entry);
        renderCustomFontList();
        buildTextSettings();
        if (!quiet) showToast(`فونت «${entry.name}» به کتابخانه اضافه شد.`);
        return entry;
    }

    function renderCustomFontList() {
        el.customFontList.replaceChildren();
        state.customFonts.forEach(font => {
            const row = document.createElement('div');
            row.className = 'lm-custom-font-item';
            const name = document.createElement('span');
            name.textContent = `${font.name} · ${Math.ceil(font.size / 1024)} KB`;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'lm-font-remove';
            remove.textContent = 'حذف';
            remove.title = `حذف فونت ${font.name}`;
            remove.addEventListener('click', () => removeCustomFont(font.family));
            row.append(name, remove);
            el.customFontList.appendChild(row);
        });
        const total = state.customFonts.reduce((sum, font) => sum + font.size, 0);
        el.fontLibraryStatus.textContent = state.customFonts.length
            ? `${state.customFonts.length.toLocaleString('fa-IR')} فونت · ${(total / 1024 / 1024).toLocaleString('fa-IR', { maximumFractionDigits: 1 })} مگابایت استفاده‌شده · فقط روی دستگاه شما`
            : 'WOFF2، WOFF، TTF یا OTF · حداکثر ۸ مگابایت برای هر فایل؛ فقط روی دستگاه شما';
    }

    function removeCustomFont(family) {
        const index = state.customFonts.findIndex(font => font.family === family);
        if (index < 0) return;
        const [font] = state.customFonts.splice(index, 1);
        try { document.fonts.delete(font.face); } catch (error) {}
        URL.revokeObjectURL(font.objectUrl);
        allTextItems().forEach(item => {
            const style = state.textStyles[item.key];
            if (style && style.fontFamily === family) style.fontFamily = (item.defaults || TEXT_ITEMS[0].defaults).fontFamily;
        });
        renderCustomFontList();
        buildTextSettings();
        showToast(`فونت «${font.name}» از کتابخانه حذف شد.`);
    }

    async function handleCustomFontFiles(files) {
        const list = Array.from(files || []);
        for (const file of list) {
            try { await registerCustomFont(file, false); }
            catch (error) { showToast(error.message || 'بارگذاری فونت انجام نشد.', 'warning'); }
        }
        el.customFontFiles.value = '';
    }

    function clearCustomFontLibrary() {
        state.customFonts.forEach(font => {
            try { document.fonts.delete(font.face); } catch (error) {}
            URL.revokeObjectURL(font.objectUrl);
        });
        state.customFonts = [];
        renderCustomFontList();
    }

    function getSelectedAudioTrack() {
        return state.audioTracks.find(track => track.id === state.selectedAudioTrackId) || null;
    }

    function syncAudioAliases(track = getSelectedAudioTrack()) {
        state.audioFile = track ? track.file : null;
        state.audioUrl = track ? track.url : null;
        state.audioDuration = track ? track.duration : 0;
        state.audioStart = track ? track.start : 0;
        state.audioPeaks = track ? track.peaks : [];
        state.audioVolume = track ? track.volume : 1;
        state.audioFadeIn = track ? track.fadeIn : .5;
        state.audioFadeOut = track ? track.fadeOut : 1;
        state.audioLoop = track ? track.loop : false;
    }

    function ensureAudioContext() {
        if (state.audioContext) return true;
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) return false;
        try {
            const context = new AudioContextClass();
            state.audioContext = context;
            state.audioDestination = context.createMediaStreamDestination();
            context.resume().catch(() => {});
            return true;
        } catch (error) {
            return false;
        }
    }

    function ensureAudioGraph(track) {
        if (!track) return false;
        if (track.gainNode && state.audioContext) return true;
        if (!ensureAudioContext()) return false;
        try {
            track.sourceNode = state.audioContext.createMediaElementSource(track.element);
            track.gainNode = state.audioContext.createGain();
            track.sourceNode.connect(track.gainNode);
            track.gainNode.connect(state.audioContext.destination);
            track.gainNode.connect(state.audioDestination);
            return true;
        } catch (error) {
            track.sourceNode = null;
            track.gainNode = null;
            return false;
        }
    }

    function updateAudioControls() {
        const track = getSelectedAudioTrack();
        syncAudioAliases(track);
        const hasTrack = !!track;
        if (el.selectedAudioControls) el.selectedAudioControls.hidden = !hasTrack;
        el.removeAudio.disabled = !hasTrack || state.exporting || state.exportPreparing;
        if (!track) {
            el.audioFileName.textContent = state.audioTracks.length ? 'ترکی انتخاب نشده' : 'ترکی انتخاب نشده';
            el.audioFileMeta.textContent = 'حداکثر ۱۲ ترک · هر فایل ۴۰ مگابایت · مجموع ذخیره‌ی پروژه ۵۰ مگابایت';
            const waveform = document.getElementById('audioWaveform');
            const waveContext = waveform && waveform.getContext('2d');
            if (waveContext) waveContext.clearRect(0, 0, waveform.width, waveform.height);
            return;
        }
        el.audioFileName.textContent = track.name;
        const durationText = track.duration ? ` · ${secondsLabel(track.duration)}` : '';
        const sizeText = track.file ? ` · ${(track.file.size / 1024 / 1024).toLocaleString('fa-IR', { maximumFractionDigits: 1 })} مگابایت` : '';
        el.audioFileMeta.textContent = `${track.kind === 'voice' ? 'گفتار' : 'موسیقی'} · ${(track.file && track.file.type) || 'فایل صوتی'}${sizeText}${durationText}`;
        el.audioVolume.value = String(Math.round(track.volume * 100));
        el.audioVolumeValue.textContent = `${Math.round(track.volume * 100).toLocaleString('fa-IR')}٪`;
        el.audioStart.max = String(state.duration);
        track.start = clamp(track.start, 0, state.duration);
        el.audioStart.value = track.start.toFixed(1);
        el.audioStartValue.textContent = secondsLabel(track.start);
        el.audioFadeIn.value = track.fadeIn.toFixed(1);
        el.audioFadeInValue.textContent = secondsLabel(track.fadeIn);
        el.audioFadeOut.value = track.fadeOut.toFixed(1);
        el.audioFadeOutValue.textContent = secondsLabel(track.fadeOut);
        el.audioMode.value = track.loop ? 'loop' : 'once';
        track.element.loop = track.loop;
        renderSelectedAudioWaveform(track);
    }

    function renderSelectedAudioWaveform(track = getSelectedAudioTrack()) {
        const waveform = document.getElementById('audioWaveform');
        const waveContext = waveform && waveform.getContext('2d');
        if (!waveContext) return;
        waveContext.clearRect(0, 0, waveform.width, waveform.height);
        if (!track || !track.peaks.length) return;
        const bars = Math.min(track.peaks.length, waveform.width);
        const middle = waveform.height / 2;
        waveContext.fillStyle = track.kind === 'voice' ? state.gold : state.accent;
        for (let index = 0; index < bars; index += 1) {
            const peak = track.peaks[Math.floor(index * track.peaks.length / bars)] || 0;
            const height = Math.max(2, peak * waveform.height * .84);
            waveContext.globalAlpha = .35 + peak * .65;
            waveContext.fillRect(index * waveform.width / bars, middle - height / 2, Math.max(1, waveform.width / bars - 1), height);
        }
        waveContext.globalAlpha = 1;
    }

    function renderAudioTrackList() {
        if (!el.audioTrackList) return;
        el.audioTrackList.replaceChildren();
        if (!state.audioTracks.length) {
            const empty = document.createElement('p');
            empty.className = 'lm-layer-empty-note lm-audio-empty-note';
            empty.textContent = 'ترک صوتی ندارید. موسیقی و گفتار را هرکدام چندبار اضافه کنید.';
            el.audioTrackList.appendChild(empty);
            updateAudioControls();
            return;
        }
        state.audioTracks.forEach(track => {
            const card = document.createElement('div');
            card.className = `lm-audio-track-card${track.id === state.selectedAudioTrackId ? ' is-selected' : ''}${track.kind === 'voice' ? ' is-voice' : ''}`;
            const select = document.createElement('button');
            select.type = 'button';
            select.className = 'lm-audio-track-select';
            select.setAttribute('aria-pressed', track.id === state.selectedAudioTrackId ? 'true' : 'false');
            const badge = document.createElement('span');
            badge.className = 'lm-audio-track-badge';
            badge.textContent = track.kind === 'voice' ? 'گفتار' : 'موسیقی';
            const info = document.createElement('span');
            info.className = 'lm-audio-track-info';
            const name = document.createElement('b');
            name.textContent = track.name;
            const meta = document.createElement('small');
            const duration = track.duration ? secondsLabel(track.duration) : 'در حال خواندن…';
            meta.textContent = `${duration} · شروع ${secondsLabel(track.start)} · ${Math.round(track.volume * 100).toLocaleString('fa-IR')}٪`;
            info.append(name, meta);
            select.append(badge, info);
            select.addEventListener('click', () => selectAudioTrack(track.id));
            const actions = document.createElement('div');
            actions.className = 'lm-audio-track-actions';
            const mute = document.createElement('button');
            mute.type = 'button';
            mute.className = 'lm-audio-track-action' + (track.muted ? ' is-muted' : '');
            mute.textContent = track.muted ? 'بی‌صدا' : 'صدا';
            mute.setAttribute('aria-label', track.muted ? `فعال‌کردن صدای ${track.name}` : `بی‌صداکردن ${track.name}`);
            mute.addEventListener('click', () => {
                track.muted = !track.muted;
                renderAudioTrackList();
                syncAudioPlayback(currentTime(performance.now()), true);
            });
            const duplicate = document.createElement('button');
            duplicate.type = 'button';
            duplicate.className = 'lm-audio-track-action';
            duplicate.textContent = '⧉';
            duplicate.title = `تکثیر ${track.name}`;
            duplicate.setAttribute('aria-label', `تکثیر ${track.name}`);
            duplicate.addEventListener('click', () => addAudioTrackFile(track.file, track.kind, { ...track, name: `${track.name} · کپی` }, false));
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'lm-audio-track-action is-remove';
            remove.textContent = '×';
            remove.title = `حذف ${track.name}`;
            remove.setAttribute('aria-label', `حذف ${track.name}`);
            remove.addEventListener('click', () => removeAudioTrack(track.id, false));
            actions.append(mute, duplicate, remove);
            card.append(select, actions);
            el.audioTrackList.appendChild(card);
        });
        updateAudioControls();
    }

    function selectAudioTrack(id) {
        if (!state.audioTracks.some(track => track.id === id)) return;
        state.selectedAudioTrackId = id;
        state.audioLastSync = 0;
        updateAudioControls();
        renderAudioTrackList();
        renderTimelineTracks();
        syncAudioPlayback(currentTime(performance.now()), true);
    }

    function updateAudioMeta(track) {
        if (!track) return;
        const duration = Number(track.element.duration);
        if (Number.isFinite(duration) && duration > 0) track.duration = duration;
        track.start = clamp(track.start, 0, state.duration);
        if (track.id === state.selectedAudioTrackId) updateAudioControls();
        renderAudioTrackList();
        renderTimelineTracks();
    }

    async function drawAudioWaveform(track) {
        if (!track || !track.file || track.file.size > 16 * 1024 * 1024) return;
        if (!ensureAudioContext()) return;
        try {
            const decoded = await state.audioContext.decodeAudioData(await track.file.arrayBuffer());
            if (!state.audioTracks.includes(track)) return;
            track.duration = decoded.duration || track.duration;
            const samples = decoded.getChannelData(0);
            const peakCount = 96;
            const stride = Math.max(1, Math.floor(samples.length / peakCount));
            const peaks = [];
            for (let bar = 0; bar < peakCount; bar += 1) {
                let peak = 0;
                const end = Math.min(samples.length, (bar + 1) * stride);
                for (let index = bar * stride; index < end; index += 1) peak = Math.max(peak, Math.abs(samples[index]));
                peaks.push(peak);
            }
            track.peaks = peaks;
            if (track.id === state.selectedAudioTrackId) updateAudioControls();
            renderAudioTrackList();
            renderTimelineTracks();
        } catch (error) {
            // Waveform decoration is optional; native audio playback and export remain available.
        }
    }

    function isSupportedAudioFile(file) {
        return !!file && (String(file.type || '').startsWith('audio/') || /\.(mp3|wav|ogg|m4a|aac|flac|opus)$/i.test(file.name));
    }

    function createAudioElement() {
        if (!state.audioPreviewAssigned) {
            state.audioPreviewAssigned = true;
            return el.audioPreview;
        }
        const element = new Audio();
        element.preload = 'metadata';
        return element;
    }

    async function addAudioTrackFile(file, kind = 'music', settings = {}, quiet = false, preferredId = '') {
        if (!file || state.exporting || state.exportPreparing) return null;
        if (!isSupportedAudioFile(file)) {
            if (!quiet) showToast('فایل صوتی MP3، WAV، OGG، M4A، AAC یا FLAC انتخاب کنید.', 'warning');
            return null;
        }
        if (!file.size || file.size > MAX_AUDIO_BYTES) {
            if (!quiet) showToast('حجم هر فایل صوتی نباید بیشتر از ۴۰ مگابایت باشد.', 'warning');
            return null;
        }
        if (state.audioTracks.length >= MAX_AUDIO_TRACKS) {
            if (!quiet) showToast(`حداکثر ${MAX_AUDIO_TRACKS} ترک موسیقی و گفتار به هر پروژه اضافه می‌شود.`, 'warning');
            return null;
        }
        state.audioTrackSequence += 1;
        const safeKind = kind === 'voice' ? 'voice' : 'music';
        const safeId = typeof preferredId === 'string' && /^[a-zA-Z0-9_-]{1,80}$/.test(preferredId) && !state.audioTracks.some(track => track.id === preferredId)
            ? preferredId
            : `audio-${Date.now().toString(36)}-${state.audioTrackSequence.toString(36)}`;
        const url = URL.createObjectURL(file);
        const element = createAudioElement();
        const track = {
            id: safeId,
            kind: safeKind,
            name: String(settings.name || file.name || (safeKind === 'voice' ? 'گفتار' : 'موسیقی')).slice(0, 120),
            file,
            url,
            element,
            duration: 0,
            start: clamp(Number(settings.start) || 0, 0, state.duration),
            volume: clamp(Number.isFinite(Number(settings.volume)) ? Number(settings.volume) : 1, 0, 1.5),
            fadeIn: clamp(Number.isFinite(Number(settings.fadeIn)) ? Number(settings.fadeIn) : .5, 0, 5),
            fadeOut: clamp(Number.isFinite(Number(settings.fadeOut)) ? Number(settings.fadeOut) : 1, 0, 5),
            loop: !!settings.loop,
            muted: !!settings.muted,
            peaks: [],
            sourceNode: null,
            gainNode: null,
            lastSync: 0,
            lastTimelineTime: -1
        };
        state.audioTracks.push(track);
        state.selectedAudioTrackId = track.id;
        state.audioLastSync = 0;
        state.audioLastTimelineTime = -1;
        element.addEventListener('loadedmetadata', () => updateAudioMeta(track));
        element.addEventListener('error', () => {
            if (state.audioTracks.includes(track)) showToast(`پخش «${track.name}» در مرورگر ممکن نیست؛ فایل دیگری را انتخاب کنید.`, 'warning');
        });
        element.src = url;
        element.loop = track.loop;
        element.load();
        ensureAudioGraph(track);
        syncAudioAliases(track);
        updateAudioControls();
        renderAudioTrackList();
        renderTimelineTracks();
        await drawAudioWaveform(track);
        syncAudioPlayback(currentTime(performance.now()), true);
        if (!quiet) showToast(`${safeKind === 'voice' ? 'ترک گفتار' : 'ترک موسیقی'} اضافه شد؛ هر ترک زمان‌بندی و میکس مستقل دارد.`);
        return track;
    }

    async function addAudioFiles(files, kind = 'music', quiet = false) {
        const list = Array.from(files || []);
        if (!list.length) return;
        let added = 0;
        let skipped = 0;
        for (const file of list) {
            if (state.audioTracks.length >= MAX_AUDIO_TRACKS) {
                skipped += 1;
                continue;
            }
            if (!isSupportedAudioFile(file) || !file.size || file.size > MAX_AUDIO_BYTES) {
                skipped += 1;
                continue;
            }
            const track = await addAudioTrackFile(file, kind, {}, true);
            if (track) added += 1;
            else skipped += 1;
        }
        if (!quiet && added) showToast(`${added.toLocaleString('fa-IR')} ترک ${kind === 'voice' ? 'گفتار' : 'موسیقی'} اضافه شد.`);
        if (!quiet && skipped) showToast(skipped >= list.length ? 'فایل صوتی نامعتبر، بزرگ‌تر از ۴۰ مگابایت یا بیش از سقف ۱۲ ترک بود.' : `${skipped.toLocaleString('fa-IR')} فایل به‌دلیل نوع یا حجم نامعتبر رد شد.`, 'warning');
    }

    async function handleAudioFile(file, quiet, kind = 'music') {
        return addAudioTrackFile(file, kind, {}, quiet);
    }

    function removeAudioTrack(id, quiet) {
        const track = state.audioTracks.find(item => item.id === id);
        if (!track) return;
        track.element.pause();
        if (track.gainNode) {
            try { track.gainNode.gain.value = 0; track.gainNode.disconnect(); } catch (error) {}
        }
        if (track.sourceNode) { try { track.sourceNode.disconnect(); } catch (error) {} }
        try { URL.revokeObjectURL(track.url); } catch (error) {}
        track.element.removeAttribute('src');
        try { track.element.load(); } catch (error) {}
        state.audioTracks = state.audioTracks.filter(item => item.id !== id);
        state.keyframes = state.keyframes.filter(frame => frame.target !== `audioTrack:${id}`);
        if (state.selectedKeyframeId && !state.keyframes.some(frame => frame.id === state.selectedKeyframeId)) state.selectedKeyframeId = null;
        if (state.selectedAudioTrackId === id) state.selectedAudioTrackId = state.audioTracks[0] ? state.audioTracks[0].id : null;
        state.timelinePointerDrag = null;
        state.audioLastSync = 0;
        state.audioLastTimelineTime = -1;
        syncAudioAliases();
        updateAudioControls();
        renderAudioTrackList();
        renderTimelineTracks();
        syncAudioPlayback(currentTime(performance.now()), true);
        if (!quiet) showToast('ترک صوتی انتخاب‌شده حذف شد.');
    }

    function clearAudioTracks(quiet) {
        state.audioTracks.slice().forEach(track => {
            track.element.pause();
            if (track.gainNode) { try { track.gainNode.disconnect(); } catch (error) {} }
            if (track.sourceNode) { try { track.sourceNode.disconnect(); } catch (error) {} }
            try { URL.revokeObjectURL(track.url); } catch (error) {}
            track.element.removeAttribute('src');
            try { track.element.load(); } catch (error) {}
        });
        state.audioTracks = [];
        state.selectedAudioTrackId = null;
        state.timelinePointerDrag = null;
        state.audioLastSync = 0;
        state.audioLastTimelineTime = -1;
        syncAudioAliases(null);
        updateAudioControls();
        renderAudioTrackList();
        renderTimelineTracks();
        if (!quiet) showToast('همه‌ی ترک‌های صوتی حذف شدند.');
    }

    function syncAudioPlayback(time, force) {
        if (!state.audioTracks.length) return;
        const active = state.playing || state.exporting;
        const now = performance.now();
        if (state.audioLastTimelineTime >= 0 && time < state.audioLastTimelineTime - .05) force = true;
        state.audioLastTimelineTime = time;
        if (active) ensureAudioContext();
        if (state.audioContext && state.audioContext.state === 'suspended' && active) state.audioContext.resume().catch(() => {});
        state.audioTracks.forEach(track => {
            const element = track.element;
            const hasGraph = ensureAudioGraph(track);
            const context = state.audioContext;
            const relative = time - track.start;
            const beforeStart = relative < 0;
            const duration = track.duration || Number(element.duration) || 0;
            if (!active || beforeStart || (duration > 0 && !track.loop && relative >= duration) || track.muted) {
                element.pause();
                if (track.gainNode && context) track.gainNode.gain.setTargetAtTime(0, context.currentTime, .025);
                else element.volume = 0;
                return;
            }
            let audioTime = relative;
            if (track.loop && duration > 0) audioTime %= duration;
            if (duration > 0) audioTime = clamp(audioTime, 0, Math.max(0, duration - .03));
            if (force || now - track.lastSync > 500) {
                if (Number.isFinite(element.duration) && element.readyState >= 1 && Math.abs((Number(element.currentTime) || 0) - audioTime) > .22) {
                    try { element.currentTime = audioTime; } catch (error) {}
                }
                track.lastSync = now;
            }
            track.lastTimelineTime = time;
            element.loop = track.loop;
            if (element.paused) element.play().catch(() => {});
            const target = `audioTrack:${track.id}`;
            const keyedVolume = evaluateKeyframes(target, 'volume', time, track.volume * 100) / 100;
            let gain = clamp(keyedVolume, 0, 1.5);
            if (track.fadeIn > 0) gain *= clamp(relative / track.fadeIn, 0, 1);
            const clipRemaining = Math.max(0, state.duration - time);
            const audioRemaining = duration > 0 && !track.loop ? Math.max(0, duration - relative) : clipRemaining;
            const fadeRemaining = Math.min(clipRemaining, audioRemaining);
            if (track.fadeOut > 0) gain *= clamp(fadeRemaining / track.fadeOut, 0, 1);
            if (hasGraph && track.gainNode && context) track.gainNode.gain.setTargetAtTime(gain, context.currentTime, .025);
            else element.volume = clamp(gain, 0, 1);
        });
    }

    function addAudioMixTrack(stream) {
        if (!state.audioTracks.length || !stream || typeof stream.addTrack !== 'function') return;
        state.audioTracks.forEach(track => ensureAudioGraph(track));
        let mixedTrack = state.audioDestination && state.audioDestination.stream.getAudioTracks()[0];
        if (mixedTrack) {
            stream.addTrack(typeof mixedTrack.clone === 'function' ? mixedTrack.clone() : mixedTrack);
            return;
        }
        let added = 0;
        state.audioTracks.forEach(track => {
            const capture = track.element.captureStream || track.element.mozCaptureStream;
            if (typeof capture !== 'function') return;
            const audioStream = capture.call(track.element);
            const audioTrack = audioStream && audioStream.getAudioTracks()[0];
            if (audioTrack) {
                stream.addTrack(typeof audioTrack.clone === 'function' ? audioTrack.clone() : audioTrack);
                added += 1;
            }
        });
        if (!added) showToast('این مرورگر امکان ترکیب صدای فایل‌ها با ویدئو را نمی‌دهد.', 'warning');
        else if (state.audioTracks.length > 1) showToast('ترکیب چند ترک به پشتیبانی Web Audio مرورگر وابسته است.', 'warning');
    }

    function blobToDataUrl(blob) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(String(reader.result || ''));
            reader.onerror = () => reject(new Error('خواندن فایل برای ذخیره‌ی پروژه انجام نشد.'));
            reader.readAsDataURL(blob);
        });
    }

    function dataUrlToFile(dataUrl, fileName, maxBytes) {
        if (typeof dataUrl !== 'string' || dataUrl.length > maxBytes * 1.5 + 2048) throw new Error('فایل در پروژه بیش از اندازه بزرگ است.');
        const match = dataUrl.match(/^data:([\w.+/-]+);base64,([A-Za-z0-9+/=]+)$/);
        if (!match) throw new Error('داده‌ی فایل پروژه معتبر نیست.');
        const binary = atob(match[2]);
        if (binary.length > maxBytes) throw new Error('حجم فایل پروژه از حد مجاز بیشتر است.');
        const bytes = new Uint8Array(binary.length);
        for (let index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);
        return new File([bytes], fileName, { type: match[1] });
    }

    async function saveProjectSettings() {
        if (state.exporting) return;
        try {
            const logoResponse = await fetch(state.objectUrl || DEFAULT_LOGO);
            if (!logoResponse.ok) throw new Error('لوگو برای ذخیره‌ی پروژه خوانده نشد.');
            const logoBlob = await logoResponse.blob();
            if (logoBlob.size > MAX_LOGO_BYTES) throw new Error('لوگو از حد مجاز ذخیره‌ی پروژه بزرگ‌تر است.');
            const audioBytes = state.audioTracks.reduce((total, track) => total + (track.file ? track.file.size : 0), 0);
            if (audioBytes > 50 * 1024 * 1024) throw new Error('مجموع فایل‌های صوتی برای ذخیره‌ی پروژه نباید از ۵۰ مگابایت بیشتر شود.');
            const customFonts = [];
            for (const font of state.customFonts) customFonts.push({ fileName: font.fileName, dataUrl: await blobToDataUrl(font.file) });
            const audioTracks = [];
            for (const track of state.audioTracks) {
                audioTracks.push({
                    id: track.id, kind: track.kind, name: track.name, fileName: track.file.name,
                    dataUrl: await blobToDataUrl(track.file), start: track.start, volume: track.volume,
                    fadeIn: track.fadeIn, fadeOut: track.fadeOut, loop: track.loop, muted: track.muted
                });
            }
            const selectedTrack = getSelectedAudioTrack();
            const project = {
                schema: 'sahand-logo-motion-project',
                version: 2,
                savedAt: new Date().toISOString(),
                brand: { title: state.title, tagline: state.tagline, phone: state.phone, website: state.website, englishText: state.englishText },
                textStyles: state.textStyles,
                extraTextLayers: state.extraTextLayers,
                logoLayers: state.logoLayers,
                keyframes: state.keyframes,
                customFonts,
                audio: null,
                audioTracks,
                selectedAudioTrackId: state.selectedAudioTrackId,
                logo: { fileName: el.fileName.textContent || 'logo', dataUrl: await blobToDataUrl(logoBlob) },
                logoMotion: { logoScale: state.logoScale, easing: state.logoEasing, entryTime: state.logoEntryTime, entryDuration: state.logoEntryDuration, exitEffect: state.logoExitEffect, exitTime: state.logoExitTime, exitDuration: state.logoExitDuration, exitAuto: state.logoExitAuto, intensity: state.motionIntensity, styleId: state.motionStyle.id },
                background: { color: state.background, intensity: state.backgroundIntensity, speed: state.backgroundSpeed, animationId: state.backgroundAnimation.id },
                colors: { accent: state.accent, gold: state.gold },
                audioSettings: selectedTrack ? { start: selectedTrack.start, volume: selectedTrack.volume, fadeIn: selectedTrack.fadeIn, fadeOut: selectedTrack.fadeOut, loop: selectedTrack.loop } : {},
                output: { duration: state.duration, resolution: state.resolution, aspect: state.aspect, fps: state.fps, format: state.format, codec: state.codec, bitrate: state.bitrate }
            };
            const blob = new Blob([JSON.stringify(project, null, 2)], { type: 'application/json' });
            if (blob.size > MAX_PROJECT_BYTES) throw new Error('فایل پروژه از سقف ۱۰۰ مگابایت بزرگ‌تر شد؛ اندازه‌ی فایل‌های صوتی را کاهش دهید.');
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'sahand-logo-motion-project.json';
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 15000);
            showToast('پروژه همراه با همه‌ی لایه‌ها، موسیقی، گفتار، فونت‌ها و کی‌فریم‌ها ذخیره شد.');
        } catch (error) {
            showToast(error.message || 'ذخیره‌ی پروژه انجام نشد.', 'error');
        }
    }

    function isHexColor(value) {
        return typeof value === 'string' && /^#[0-9a-f]{6}$/i.test(value);
    }

    function sanitizeImportedTextLayers(value) {
        if (!Array.isArray(value)) return [];
        const safeTemplates = new Set([...TEXT_ITEMS.map(item => item.key), 'custom']);
        const seen = new Set();
        return value.slice(0, 24).filter(item => item && typeof item === 'object').map((item, index) => {
            const requestedKey = typeof item.key === 'string' && /^text-(?:layer|import)-[a-zA-Z0-9_-]{1,70}$/.test(item.key) ? item.key : `text-import-${index + 1}`;
            let key = requestedKey;
            let suffix = 1;
            while (seen.has(key) || TEXT_ITEMS.some(base => base.key === key)) { key = `${requestedKey}-${suffix++}`; }
            seen.add(key);
            const templateKey = safeTemplates.has(item.templateKey) ? item.templateKey : 'custom';
            const source = TEXT_ITEMS.find(base => base.key === templateKey) || TEXT_ITEMS[0];
            const label = typeof item.label === 'string' ? item.label.slice(0, 56) : `نوشته‌ی جدید ${index + 1}`;
            return {
                key,
                label: label || `نوشته‌ی جدید ${index + 1}`,
                templateKey,
                defaultY: clamp(Number(item.defaultY) || (templateKey === 'custom' ? 52 : source.defaultY), 4, 96),
                rtl: typeof item.rtl === 'boolean' ? item.rtl : source.rtl,
                icon: templateKey === 'phone' ? 'phone' : (templateKey === 'website' ? 'web' : ''),
                value: String(item.value || '').slice(0, 120),
                defaults: { ...source.defaults }
            };
        });
    }

    function sanitizeImportedLogoLayers(value) {
        if (!Array.isArray(value)) return [];
        const seen = new Set();
        return value.slice(0, 8).filter(item => item && typeof item === 'object').map((item, index) => {
            const requestedId = typeof item.id === 'string' && /^[a-zA-Z0-9_-]{1,80}$/.test(item.id) ? item.id : `logo-import-${index + 1}`;
            let id = requestedId;
            let suffix = 1;
            while (seen.has(id)) id = `${requestedId}-${suffix++}`;
            seen.add(id);
            const number = (key, fallback, min, max) => {
                const incoming = Number(item[key]);
                return clamp(Number.isFinite(incoming) ? incoming : fallback, min, max);
            };
            return {
                id,
                label: typeof item.label === 'string' ? item.label.slice(0, 50) : `کپی لوگو ${index + 1}`,
                scale: number('scale', 42, 15, 100),
                xOffset: number('xOffset', index % 2 ? 25 : -25, -45, 45),
                yOffset: number('yOffset', 0, -35, 35),
                opacity: number('opacity', 100, 0, 100),
                entryTime: number('entryTime', .5, 0, state.duration),
                entryDuration: number('entryDuration', .7, .2, Math.min(3, state.duration)),
                exitTime: number('exitTime', state.duration - .8, 0, state.duration),
                exitDuration: number('exitDuration', .55, .2, Math.min(3, state.duration)),
                exitAuto: typeof item.exitAuto === 'boolean' ? item.exitAuto : true
            };
        });
    }

    function sanitizeImportedTextStyles(styles) {
        const next = {};
        const familySet = new Set([...FONT_PRESETS.map(item => item[0]), ...state.customFonts.map(font => font.family)]);
        const effectSet = new Set(TEXT_EFFECTS.map(item => item[0]));
        const exitSet = new Set(TEXT_EXIT_EFFECTS.map(item => item[0]));
        const easingSet = new Set(EASING_OPTIONS.map(item => item[0]));
        const limits = {
            fontSize: [10, 120], letterSpacing: [-2, 18], xOffset: [-30, 30], yOffset: [-25, 25], maxWidth: [20, 96], opacity: [10, 100],
            strokeWidth: [0, 8], shadowBlur: [0, 40], entryTime: [0, state.duration], entryDuration: [.2, 3], exitTime: [0, state.duration], exitDuration: [.2, 3]
        };
        allTextItems().forEach(item => {
            const defaults = { ...(item.defaults || TEXT_ITEMS[0].defaults) };
            const incoming = styles && styles[item.key] && typeof styles[item.key] === 'object' ? styles[item.key] : {};
            const merged = { ...defaults };
            Object.keys(limits).forEach(property => {
                const value = Number(incoming[property]);
                if (Number.isFinite(value)) merged[property] = clamp(value, limits[property][0], limits[property][1]);
            });
            if (familySet.has(incoming.fontFamily)) merged.fontFamily = incoming.fontFamily;
            if (WEIGHT_OPTIONS.some(option => option[0] === String(incoming.fontWeight))) merged.fontWeight = String(incoming.fontWeight);
            if (ALIGN_OPTIONS.some(option => option[0] === incoming.alignment)) merged.alignment = incoming.alignment;
            if (effectSet.has(incoming.enterEffect)) merged.enterEffect = incoming.enterEffect;
            if (exitSet.has(incoming.exitEffect)) merged.exitEffect = incoming.exitEffect;
            if (easingSet.has(incoming.easing)) merged.easing = incoming.easing;
            if (['upper', 'normal', 'lower'].includes(incoming.caseMode)) merged.caseMode = incoming.caseMode;
            ['italic', 'autoColor', 'exitAuto'].forEach(property => { if (typeof incoming[property] === 'boolean') merged[property] = incoming[property]; });
            ['color', 'strokeColor', 'shadowColor'].forEach(property => { if (isHexColor(incoming[property])) merged[property] = incoming[property]; });
            next[item.key] = merged;
        });
        return next;
    }

    function applyImportedSelect(select, value) {
        if (Array.from(select.options).some(option => option.value === String(value))) select.value = String(value);
    }

    async function loadProjectSettings(file) {
        if (!file) return;
        if (file.size > MAX_PROJECT_BYTES) { showToast('فایل پروژه نباید بیشتر از ۱۰۰ مگابایت باشد.', 'warning'); return; }
        try {
            const project = JSON.parse(await file.text());
            if (!project || project.schema !== 'sahand-logo-motion-project' || ![1, 2].includes(Number(project.version))) throw new Error('فایل پروژه‌ی سهند سرویس معتبر نیست.');
            if (!project.brand || !project.output || !project.logoMotion || !project.textStyles) throw new Error('بخشی از تنظیمات فایل پروژه ناقص است.');

            const importedFonts = [];
            if (Array.isArray(project.customFonts)) {
                for (const font of project.customFonts.slice(0, MAX_CUSTOM_FONT_COUNT)) {
                    if (!font || typeof font.fileName !== 'string' || typeof font.dataUrl !== 'string') continue;
                    importedFonts.push(dataUrlToFile(font.dataUrl, font.fileName, MAX_CUSTOM_FONT_BYTES));
                }
            }
            clearCustomFontLibrary();
            for (const font of importedFonts) await registerCustomFont(font, true);

            const logoFile = dataUrlToFile(project.logo && project.logo.dataUrl, (project.logo && project.logo.fileName) || 'logo.png', MAX_LOGO_BYTES);
            let logoBlob = logoFile.slice(0, logoFile.size, logoFile.type || 'application/octet-stream');
            const lowerName = logoFile.name.toLowerCase();
            if (lowerName.endsWith('.svg') || logoFile.type === 'image/svg+xml') {
                const cleanSvg = sanitizeSvg(await logoFile.text());
                logoBlob = new Blob([cleanSvg], { type: 'image/svg+xml' });
            } else if (!['image/png', 'image/jpeg', 'image/webp'].includes(logoFile.type)) {
                throw new Error('لوگوی داخل پروژه باید PNG، JPG، WebP یا SVG باشد.');
            }
            const importedLogoUrl = URL.createObjectURL(logoBlob);

            state.title = String(project.brand.title || '').slice(0, 32);
            state.tagline = String(project.brand.tagline || '').slice(0, 56);
            state.phone = String(project.brand.phone || '').slice(0, 28);
            state.website = String(project.brand.website || '').slice(0, 48);
            state.englishText = String(project.brand.englishText || '').slice(0, 48);
            el.brandName.value = state.title;
            el.tagline.value = state.tagline;
            el.phone.value = state.phone;
            el.website.value = state.website;
            el.brandEnglish.value = state.englishText;

            state.duration = clamp(Number(project.output.duration) || 8, 1, 30);
            state.extraTextLayers = sanitizeImportedTextLayers(project.extraTextLayers);
            state.textLayerSequence = state.extraTextLayers.length;
            state.logoLayers = sanitizeImportedLogoLayers(project.logoLayers);
            state.logoLayerSequence = state.logoLayers.length;
            state.textStyles = sanitizeImportedTextStyles(project.textStyles);
            const motion = project.logoMotion;
            state.logoScale = clamp(Number(motion.logoScale) || 1, .55, 1.45);
            state.logoEasing = EASING_OPTIONS.some(option => option[0] === motion.easing) ? motion.easing : 'cinematic';
            state.logoEntryTime = clamp(Number(motion.entryTime) || 0, 0, state.duration);
            state.logoEntryDuration = clamp(Number(motion.entryDuration) || 1.3, .4, 2.6);
            state.logoExitEffect = ['none', 'fade', 'zoom', 'rise', 'rotate', 'glitch'].includes(motion.exitEffect) ? motion.exitEffect : 'none';
            state.logoExitTime = clamp(Number(motion.exitTime) || 0, 0, state.duration);
            state.logoExitDuration = clamp(Number(motion.exitDuration) || .6, .2, 2);
            state.logoExitAuto = motion.exitAuto !== false;
            const importedMotionIntensity = Number(motion.intensity);
            state.motionIntensity = Number.isFinite(importedMotionIntensity) ? clamp(importedMotionIntensity, 0, 1.5) : 1;
            state.motionStyle = MOTION_STYLES.find(style => style.id === motion.styleId) || MOTION_STYLES[0];

            const background = project.background || {};
            state.background = isHexColor(background.color) ? background.color : BACKGROUND_COLORS[0].value;
            state.backgroundIntensity = clamp(Number(background.intensity) || 0, 0, 1);
            state.backgroundSpeed = clamp(Number(background.speed) || 1, .25, 2);
            state.backgroundAnimation = BACKGROUND_ANIMATIONS.find(animation => animation.id === background.animationId) || BACKGROUND_ANIMATIONS[0];
            const colors = project.colors || {};
            state.accent = isHexColor(colors.accent) ? colors.accent : COLOR_PALETTES[0].accent;
            state.gold = isHexColor(colors.gold) ? colors.gold : COLOR_PALETTES[0].gold;

            el.logoScale.value = String(Math.round(state.logoScale * 100));
            el.logoScaleValue.textContent = `${Number(el.logoScale.value).toLocaleString('fa-IR')}٪`;
            el.logoEasing.value = state.logoEasing;
            el.logoExitEffect.value = state.logoExitEffect;
            el.motionIntensity.value = String(Math.round(state.motionIntensity * 100));
            el.motionIntensityValue.textContent = `${Number(el.motionIntensity.value).toLocaleString('fa-IR')}٪`;
            el.backgroundColor.value = state.background;
            el.backgroundIntensity.value = String(Math.round(state.backgroundIntensity * 100));
            el.backgroundIntensityValue.textContent = `${Number(el.backgroundIntensity.value).toLocaleString('fa-IR')}٪`;
            el.backgroundSpeed.value = String(Math.round(state.backgroundSpeed * 100));
            el.backgroundSpeedValue.textContent = `${state.backgroundSpeed.toLocaleString('fa-IR', { maximumFractionDigits: 2 })}×`;
            el.accentColor.value = state.accent;
            el.goldColor.value = state.gold;

            const output = project.output;
            applyImportedSelect(el.duration, output.duration);
            applyImportedSelect(el.quality, output.resolution);
            applyImportedSelect(el.aspect, output.aspect);
            applyImportedSelect(el.fps, output.fps);
            applyImportedSelect(el.format, output.format);
            applyImportedSelect(el.codec, output.codec);
            applyImportedSelect(el.bitrate, output.bitrate);
            state.duration = Number(el.duration.value) || 8;
            state.resolution = Number(el.quality.value) || 1080;
            state.aspect = el.aspect.value || '16:9';
            state.fps = Number(el.fps.value) || 30;
            state.format = el.format.value || 'webm';
            state.codec = el.codec.value || 'auto';
            state.bitrate = el.bitrate.value || 'high';
            configureFormatOptions();
            const importedTracks = Number(project.version) >= 2 && Array.isArray(project.audioTracks)
                ? project.audioTracks.slice(0, MAX_AUDIO_TRACKS)
                : (project.audio && project.audio.dataUrl ? [{ ...project.audio, kind: 'music', name: project.audio.fileName, id: 'audio-legacy' }] : []);
            const importedAudioFiles = [];
            let importedAudioBytes = 0;
            for (const audioRecord of importedTracks) {
                if (!audioRecord || typeof audioRecord.dataUrl !== 'string') continue;
                const fallbackName = audioRecord.kind === 'voice' ? 'project-voice.mp3' : 'project-music.mp3';
                const fileName = (typeof audioRecord.fileName === 'string' ? audioRecord.fileName : fallbackName).slice(0, 160);
                const audioFile = dataUrlToFile(audioRecord.dataUrl, fileName, MAX_AUDIO_BYTES);
                importedAudioBytes += audioFile.size;
                if (importedAudioBytes > 50 * 1024 * 1024) throw new Error('مجموع فایل‌های صوتی پروژه از سقف ۵۰ مگابایت بیشتر است.');
                importedAudioFiles.push({ record: audioRecord, file: audioFile });
            }
            clearAudioTracks(true);
            for (const imported of importedAudioFiles) {
                const audioRecord = imported.record;
                await addAudioTrackFile(imported.file, audioRecord.kind === 'voice' ? 'voice' : 'music', audioRecord, true, audioRecord.id || '');
            }
            if (Number(project.version) >= 2 && state.audioTracks.some(track => track.id === project.selectedAudioTrackId)) {
                state.selectedAudioTrackId = project.selectedAudioTrackId;
            }
            syncAudioAliases();
            updateAudioControls();
            renderAudioTrackList();
            const importedKeyframes = Number(project.version) === 1 && state.audioTracks.length
                ? (Array.isArray(project.keyframes) ? project.keyframes.map(frame => frame && frame.target === 'audio' ? { ...frame, target: `audioTrack:${state.audioTracks[0].id}` } : frame) : project.keyframes)
                : project.keyframes;
            state.keyframes = sanitizeImportedKeyframes(importedKeyframes, state.duration);
            state.selectedKeyframeId = null;
            renderCustomFontList();
            buildTextSettings();
            updateTextTimingControls();
            renderLogoLayerList();
            updateLogoTimingControls();
            document.getElementById('selectedBackgroundName').textContent = state.backgroundAnimation.name;
            renderMotionStyles();
            renderBackgroundAnimations();
            document.querySelectorAll('.lm-swatch').forEach((button, index) => {
                const selected = COLOR_PALETTES[index] && COLOR_PALETTES[index].accent === state.accent && COLOR_PALETTES[index].gold === state.gold;
                button.classList.toggle('is-selected', !!selected);
                button.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
            document.querySelectorAll('.lm-bg-swatch').forEach((button, index) => {
                const selected = BACKGROUND_COLORS[index] && BACKGROUND_COLORS[index].value === state.background;
                button.classList.toggle('is-selected', !!selected);
                button.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
            setLogoSource(importedLogoUrl, logoFile.name, importedLogoUrl);
            state.offset = 0;
            state.playing = true;
            state.startedAt = performance.now();
            state.audioLastSync = 0;
            state.audioLastTimelineTime = -1;
            updateCanvasResolution();
            updateTransport(0);
            updateTimelinePlayhead(0);
            syncKeyframeValueControl();
            syncAudioPlayback(0, true);
            showToast('پروژه، همه‌ی لایه‌ها، کی‌فریم‌ها، موسیقی و گفتار بازیابی شدند.');
        } catch (error) {
            showToast(error.message || 'بارگذاری پروژه انجام نشد.', 'error');
        } finally {
            el.projectFile.value = '';
        }
    }

    function downloadFrame() {
        if (state.exporting) return;
        drawFrame(currentTime(performance.now()));
        canvas.toBlob(blob => {
            if (!blob) { showToast('ذخیره‌ی فریم انجام نشد.', 'error'); return; }
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = 'sahand-service-logo-motion-frame.png';
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 5000);
            showToast('فریم فعلی به‌صورت PNG ذخیره شد.');
        }, 'image/png');
    }

    el.brandName.addEventListener('input', () => { state.title = el.brandName.value.slice(0, 32); });
    el.tagline.addEventListener('input', () => { state.tagline = el.tagline.value.slice(0, 56); });
    el.brandEnglish.addEventListener('input', () => { state.englishText = el.brandEnglish.value.slice(0, 48); });
    el.phone.addEventListener('input', () => { state.phone = el.phone.value.slice(0, 28); });
    el.logoScale.addEventListener('input', () => {
        state.logoScale = Number(el.logoScale.value) / 100;
        el.logoScaleValue.textContent = `${Number(el.logoScale.value).toLocaleString('fa-IR')}٪`;
    });
    const handleTextSettingsEvent = event => {
        const control = event.target.closest('[data-text-key][data-text-setting]');
        if (control && el.textSettings.contains(control)) updateTextSettingFromControl(control);
    };
    el.textSettings.addEventListener('input', handleTextSettingsEvent);
    el.textSettings.addEventListener('change', handleTextSettingsEvent);
    el.addCustomFont.addEventListener('click', () => el.customFontFiles.click());
    el.customFontFiles.addEventListener('change', event => handleCustomFontFiles(event.target.files));
    el.saveProject.addEventListener('click', saveProjectSettings);
    el.loadProject.addEventListener('click', () => el.projectFile.click());
    el.projectFile.addEventListener('change', event => loadProjectSettings(event.target.files && event.target.files[0]));
    el.previewGuides.addEventListener('change', () => { el.safeGuides.hidden = !el.previewGuides.checked; });
    const openAudioPicker = (kind = 'music') => {
        if (state.exporting || state.exportPreparing) return;
        (kind === 'voice' ? el.voiceFile : el.audioFile).click();
    };
    el.audioUploadButton.addEventListener('click', () => openAudioPicker('music'));
    el.voiceUploadButton.addEventListener('click', () => openAudioPicker('voice'));
    el.timelineAddAudio.addEventListener('click', () => openAudioPicker('music'));
    el.timelineAddVoice.addEventListener('click', () => openAudioPicker('voice'));
    el.timelineAddText.addEventListener('click', () => addTextLayer(null, false));
    el.timelineAddLogo.addEventListener('click', addLogoLayer);
    el.addTextLayer.addEventListener('click', () => addTextLayer(null, false));
    el.addLogoLayer.addEventListener('click', addLogoLayer);
    el.audioFile.addEventListener('change', event => {
        addAudioFiles(event.target.files, 'music', false);
        event.target.value = '';
    });
    el.voiceFile.addEventListener('change', event => {
        addAudioFiles(event.target.files, 'voice', false);
        event.target.value = '';
    });
    el.removeAudio.addEventListener('click', () => {
        const selected = getSelectedAudioTrack();
        if (selected) removeAudioTrack(selected.id, false);
    });
    el.audioVolume.addEventListener('input', () => {
        const track = getSelectedAudioTrack();
        if (!track) return;
        track.volume = Number(el.audioVolume.value) / 100;
        syncAudioAliases(track);
        el.audioVolumeValue.textContent = `${Number(el.audioVolume.value).toLocaleString('fa-IR')}٪`;
        syncAudioPlayback(currentTime(performance.now()), true);
    });
    el.audioStart.addEventListener('input', () => {
        const track = getSelectedAudioTrack();
        if (!track) return;
        track.start = clamp(Number(el.audioStart.value) || 0, 0, state.duration);
        syncAudioAliases(track);
        el.audioStartValue.textContent = secondsLabel(track.start);
        state.audioLastSync = 0;
        renderTimelineTracks();
        syncAudioPlayback(currentTime(performance.now()), true);
    });
    el.audioFadeIn.addEventListener('input', () => {
        const track = getSelectedAudioTrack();
        if (!track) return;
        track.fadeIn = Number(el.audioFadeIn.value) || 0;
        syncAudioAliases(track);
        el.audioFadeInValue.textContent = secondsLabel(track.fadeIn);
    });
    el.audioFadeOut.addEventListener('input', () => {
        const track = getSelectedAudioTrack();
        if (!track) return;
        track.fadeOut = Number(el.audioFadeOut.value) || 0;
        syncAudioAliases(track);
        el.audioFadeOutValue.textContent = secondsLabel(track.fadeOut);
    });
    el.audioMode.addEventListener('change', () => {
        const track = getSelectedAudioTrack();
        if (!track) return;
        track.loop = el.audioMode.value === 'loop';
        track.element.loop = track.loop;
        syncAudioAliases(track);
        renderAudioTrackList();
        renderTimelineTracks();
        syncAudioPlayback(currentTime(performance.now()), true);
    });
    const handleLogoLayerSettingsEvent = event => {
        const control = event.target.closest('[data-logo-layer-id][data-logo-layer-setting]');
        if (control && el.logoLayerList.contains(control)) updateLogoLayerFromControl(control);
    };
    el.logoLayerList.addEventListener('input', handleLogoLayerSettingsEvent);
    el.logoLayerList.addEventListener('change', handleLogoLayerSettingsEvent);
    el.keyframeTarget.addEventListener('change', () => {
        state.selectedKeyframeId = null;
        updateKeyframePropertyOptions();
        renderTimelineTracks();
    });
    el.keyframeProperty.addEventListener('change', () => {
        state.selectedKeyframeId = null;
        syncKeyframeValueControl();
        renderTimelineTracks();
    });
    el.keyframeValue.addEventListener('input', () => updateSelectedKeyframeValue(el.keyframeValue.value));
    el.keyframeEasing.addEventListener('change', () => {
        const selected = state.keyframes.find(frame => frame.id === state.selectedKeyframeId);
        if (!selected) return;
        selected.easing = el.keyframeEasing.value;
        renderTimelineTracks();
        drawFrame(currentTime(performance.now()));
    });
    el.addKeyframe.addEventListener('click', addKeyframeAtPlayhead);
    el.deleteKeyframe.addEventListener('click', deleteSelectedKeyframe);
    el.logoEasing.addEventListener('change', () => { state.logoEasing = el.logoEasing.value || 'cinematic'; });
    el.logoExitEffect.addEventListener('change', () => { state.logoExitEffect = el.logoExitEffect.value || 'none'; });
    el.logoEntryTime.addEventListener('input', () => {
        state.logoEntryTime = Number(el.logoEntryTime.value);
        updateLogoTimingControls();
    });
    el.logoEntryDuration.addEventListener('input', () => {
        state.logoEntryDuration = Number(el.logoEntryDuration.value);
        updateLogoTimingControls();
    });
    el.logoExitTime.addEventListener('input', () => {
        state.logoExitAuto = false;
        state.logoExitTime = Number(el.logoExitTime.value);
        updateLogoTimingControls();
    });
    el.logoExitDuration.addEventListener('input', () => {
        state.logoExitDuration = Number(el.logoExitDuration.value);
        updateLogoTimingControls();
    });
    el.motionIntensity.addEventListener('input', () => {
        state.motionIntensity = Number(el.motionIntensity.value) / 100;
        el.motionIntensityValue.textContent = `${Number(el.motionIntensity.value).toLocaleString('fa-IR')}٪`;
    });
    el.website.addEventListener('input', () => { state.website = el.website.value.slice(0, 48); });
    el.motionSearch.addEventListener('input', renderMotionStyles);
    el.backgroundSearch.addEventListener('input', renderBackgroundAnimations);
    el.duration.addEventListener('change', () => setDuration(el.duration.value));
    el.quality.addEventListener('change', () => { state.resolution = Number(el.quality.value) || 1080; updateCanvasResolution(); });
    el.aspect.addEventListener('change', () => { state.aspect = el.aspect.value || '16:9'; updateCanvasResolution(); });
    el.fps.addEventListener('change', () => { state.fps = Number(el.fps.value) || 30; updateOutputSummary(); });
    el.format.addEventListener('change', () => {
        state.format = el.format.value || 'webm';
        syncCodecOptions();
        updateOutputSummary();
    });
    el.codec.addEventListener('change', () => { state.codec = el.codec.value || 'auto'; });
    el.bitrate.addEventListener('change', () => { state.bitrate = el.bitrate.value || 'high'; });
    el.backgroundIntensity.addEventListener('input', () => {
        state.backgroundIntensity = Number(el.backgroundIntensity.value) / 100;
        el.backgroundIntensityValue.textContent = `${Number(el.backgroundIntensity.value).toLocaleString('fa-IR')}٪`;
    });
    el.backgroundSpeed.addEventListener('input', () => {
        state.backgroundSpeed = Number(el.backgroundSpeed.value) / 100;
        el.backgroundSpeedValue.textContent = `${(state.backgroundSpeed).toLocaleString('fa-IR', { minimumFractionDigits: 1, maximumFractionDigits: 2 })}×`;
    });
    el.accentColor.addEventListener('input', () => {
        state.accent = el.accentColor.value;
        document.querySelectorAll('.lm-swatch').forEach(button => { button.classList.remove('is-selected'); button.setAttribute('aria-pressed', 'false'); });
    });
    el.goldColor.addEventListener('input', () => {
        state.gold = el.goldColor.value;
        document.querySelectorAll('.lm-swatch').forEach(button => { button.classList.remove('is-selected'); button.setAttribute('aria-pressed', 'false'); });
    });
    el.backgroundColor.addEventListener('input', () => {
        state.background = el.backgroundColor.value;
        document.querySelectorAll('.lm-bg-swatch').forEach(button => { button.classList.remove('is-selected'); button.setAttribute('aria-pressed', 'false'); });
    });

    el.uploadZone.addEventListener('click', () => el.file.click());
    el.resetLogo.addEventListener('click', () => {
        setLogoSource(DEFAULT_LOGO, 'نماد پیش‌فرض سهند سرویس', null);
        showToast('لوگوی پیش‌فرض پروژه دوباره انتخاب شد.');
    });
    el.file.addEventListener('change', event => { handleLogoFile(event.target.files && event.target.files[0]); event.target.value = ''; });
    ['dragenter', 'dragover'].forEach(type => el.uploadZone.addEventListener(type, event => { event.preventDefault(); el.uploadZone.classList.add('is-dragging'); }));
    ['dragleave', 'drop'].forEach(type => el.uploadZone.addEventListener(type, event => { event.preventDefault(); el.uploadZone.classList.remove('is-dragging'); }));
    el.uploadZone.addEventListener('drop', event => handleLogoFile(event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0]));

    document.addEventListener('keydown', event => {
        if (event.code !== 'Space' || event.repeat || state.exporting || state.exportPreparing) return;
        const target = event.target;
        if (target && (target.isContentEditable || (target.closest && target.closest('input,textarea,select,button,a,[contenteditable="true"]')))) return;
        event.preventDefault();
        el.play.click();
    });
    el.play.addEventListener('click', () => {
        if (state.exporting) return;
        if (state.playing) {
            state.offset = currentTime(performance.now());
            state.playing = false;
        } else {
            state.startedAt = performance.now();
            state.playing = true;
        }
        updateTransport(state.offset);
        updateTimelinePlayhead(state.offset);
        syncAudioPlayback(state.offset, true);
    });
    el.restart.addEventListener('click', () => {
        if (state.exporting) return;
        state.offset = 0;
        state.startedAt = performance.now();
        state.playing = true;
        state.audioLastSync = 0;
        updateTransport(0);
        updateTimelinePlayhead(0);
        syncKeyframeValueControl();
        syncAudioPlayback(0, true);
    });
    el.range.addEventListener('pointerdown', () => { state.scrubbing = true; });
    el.range.addEventListener('pointerup', () => { state.scrubbing = false; });
    el.range.addEventListener('pointercancel', () => { state.scrubbing = false; });
    el.range.addEventListener('input', () => {
        state.playing = false;
        state.offset = Number(el.range.value) / 1000 * state.duration;
        state.audioLastSync = 0;
        updateTransport(state.offset);
        updateTimelinePlayhead(state.offset);
        syncKeyframeValueControl();
        drawFrame(state.offset);
        syncAudioPlayback(state.offset, true);
    });
    el.export.addEventListener('click', startExport);
    el.videoResultClose.addEventListener('click', () => { el.videoResult.hidden = true; });
    el.frame.addEventListener('click', downloadFrame);
    window.addEventListener('resize', updateStageLayout);
    window.addEventListener('beforeunload', () => {
        if (state.objectUrl) URL.revokeObjectURL(state.objectUrl);
        if (state.exportLogoUrl) URL.revokeObjectURL(state.exportLogoUrl);
        if (state.lastVideoUrl) URL.revokeObjectURL(state.lastVideoUrl);
        state.audioTracks.forEach(track => {
            try { track.element.pause(); } catch (error) {}
            try { URL.revokeObjectURL(track.url); } catch (error) {}
        });
        if (state.audioContext && state.audioContext.state !== 'closed') state.audioContext.close().catch(() => {});
        state.customFonts.forEach(font => {
            try { document.fonts.delete(font.face); } catch (error) {}
            URL.revokeObjectURL(font.objectUrl);
        });
        stopTracks();
    });

    renderPalettes();
    renderBackgroundPalettes();
    updateCategories();
    renderMotionStyles();
    renderBackgroundAnimations();
    buildTextSettings();
    renderLogoLayerList();
    renderAudioTrackList();
    renderCustomFontList();
    configureFormatOptions();
    el.accentColor.value = state.accent;
    el.goldColor.value = state.gold;
    el.backgroundColor.value = state.background;
    if (el.bundleLink && window.fetch) {
        window.fetch(el.bundleLink.href, { method: 'HEAD', cache: 'no-store' })
            .then(response => { if (response.ok) el.bundleLink.hidden = false; })
            .catch(() => {});
    }
    el.backgroundIntensityValue.textContent = '۷۰٪';
    el.backgroundSpeedValue.textContent = '۱٫۰×';
    el.motionIntensityValue.textContent = `${Number(el.motionIntensity.value).toLocaleString('fa-IR')}٪`;
    setLogoSource(DEFAULT_LOGO, 'نماد پیش‌فرض سهند سرویس', null);
    updateCanvasResolution();
    setDuration(state.duration);
    updateAudioControls();
    updateKeyframePropertyOptions();
    renderTimelineTracks();
    if (!window.MediaRecorder || typeof canvas.captureStream !== 'function') {
        el.support.textContent = 'مرورگر شما پیش‌نمایش را نشان می‌دهد؛ برای دریافت ویدئو از Chrome یا Edge جدید استفاده کنید.';
    }
    if (document.fonts && document.fonts.load) {
        Promise.all([document.fonts.load('800 76px Vazirmatn'), document.fonts.load('500 30px Vazirmatn')]).catch(() => {});
    }
    window.requestAnimationFrame(frameLoop);
})();
