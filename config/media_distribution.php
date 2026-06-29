<?php

return [
    'base_url' => rtrim((string) env('MEDIA_DISTRIBUTION_API_BASE_URL', 'http://8.138.187.158:8082'), '/'),
    'chaojimeijie_base_url' => rtrim((string) env('CHAOJIMEIJIE_API_BASE_URL', 'https://vip.chaojimeijie.com/api'), '/'),
    'timeout' => (int) env('MEDIA_DISTRIBUTION_HTTP_TIMEOUT', 90),
    'connect_timeout' => (int) env('MEDIA_DISTRIBUTION_HTTP_CONNECT_TIMEOUT', 30),
    'retry_times' => (int) env('MEDIA_DISTRIBUTION_HTTP_RETRY_TIMES', 3),
    'retry_sleep' => (int) env('MEDIA_DISTRIBUTION_HTTP_RETRY_SLEEP', 1000),
    'page_delay_ms' => (int) env('MEDIA_DISTRIBUTION_PAGE_DELAY_MS', 800),
    'page_size' => (int) env('MEDIA_DISTRIBUTION_PAGE_SIZE', 200),
    'max_pages' => (int) env('MEDIA_DISTRIBUTION_MAX_PAGES', 200),
    'package' => [
        'platform_id' => (int) env('MEDIA_DISTRIBUTION_PACKAGE_PLATFORM_ID', 2),
        'title' => (string) env('MEDIA_DISTRIBUTION_PACKAGE_TITLE', '100家特价媒体套餐'),
        'size' => (int) env('MEDIA_DISTRIBUTION_PACKAGE_SIZE', 100),
        'published_url_type' => (string) env('MEDIA_DISTRIBUTION_PACKAGE_PUBLISHED_URL_TYPE', 'docs 文档链接'),
        'media_names' => [
            '健康网资讯',
            '第一财经网',
            '中华电商网',
            '中华财经网',
            '第一金融网',
            '中国品牌网',
            '母婴信息网',
            '时尚圈',
            '华夏财经网',
            '家居简讯',
            '中国商业圈',
            '科技战略网',
            '中华学习网',
            '财经讯',
            '汽车期刊',
            '中国财经资讯',
            '科技资讯网',
            '中国娱乐圈',
            '资讯圈',
            '财经简讯',
            '中国家电网',
            '热点资讯圈',
            '中国生活消费网',
            '中国视窗',
            '环球新闻网',
            '国民教育网',
            '历史资讯网',
            '生活消费在线',
            '中华教育培训网',
            '生活文化网',
            '时尚焦点网',
            '家居讯',
            '中国科技要闻',
            '科技简讯',
            '中华健康快报',
            '体育资讯网',
            '中国财经圈',
            '中国新闻头条',
            '财经技术网',
            '教育圈',
            '科技资讯圈',
            '天下财经网',
            '汽车资讯网',
            '汽车信息网',
            '生活消费圈',
            '家居圈',
            '第一财经新闻网',
            '焦点新闻网',
            '娱乐新闻网',
            '汽车资讯圈',
            '旅游资讯网',
            '中华历史网',
            '中国汽车馆',
            '生活馆',
            '奇闻异事网',
            '中国生活圈',
            '财经第一线',
            '教育焦点网',
            '教育讯',
            '区块链焦点网',
            '中国快讯网',
            '中国汽车头条',
            '时尚新闻网',
            '影视资讯网',
            '中娱网',
            '生活日报',
            '情感资讯网',
            '母婴资讯网',
            '亲子教育网',
            '汽车简讯',
            '中华亲子网',
            '生活消费网',
            '城市新闻网',
            '中国茶叶网',
            '中国母婴网',
            '母婴圈',
            '文化传播网',
            '经济建设网',
            '汽车热点资讯网',
            '高端时尚网',
            '综合信息网',
            '中国汽车报',
            '商业头条网',
            '网商中国',
            '潮流复古网',
            '中国商务报道网',
            '生活焦点网',
            '天天资讯网',
            '中国焦点资讯网',
            '中国生活资讯网',
            '中国商业报道',
            '家居生活网',
            '中国媒体网',
            '汽车快报网',
            '亲子圈',
            '家有宝贝网',
            '中国科技头条',
            '中华教育信息网',
            '中国科技在线',
            '城市建设网',
            '中国科技资讯圈',
            '第一产经网',
            '中国企业网',
            '中国金融网',
            '今日资讯网',
            '健康信息网',
            '健康医疗网',
            '健康专栏网',
            '美白网',
            '汽车热讯',
            '家居信息网',
            '中国教育',
            '中国家居网',
            '家居资讯网',
            '河北生活网',
            '山西新闻网',
            '安徽新闻网',
            '江苏新闻网',
            '浙江新闻网',
            '辽宁新闻网',
            '吉林新闻网',
            '黑龙江新闻网',
            '陕西新闻网',
            '河南新闻网',
            '湖北新闻网',
            '湖南新闻网',
            '江西新闻网',
            '福建新闻网',
            '云南新闻网',
            '海南新闻网',
            '四川新闻网',
            '贵州新闻网',
            '广东新闻网',
            '甘肃新闻网',
            '青海新闻网',
            '西藏新闻网',
            '新疆新闻网',
            '广西新闻网',
            '内蒙古新闻网',
            '宁夏新闻网',
            '北京新闻网',
            '天津新闻网',
            '上海新闻网',
            '重庆新闻网',
            '山东新闻网',
            '时尚动态',
            '时尚简讯',
            '时尚信息网',
            '健康焦点网',
            '中国健康简讯',
            '中华健康资讯网',
            '健康圈',
            '济南都市网',
            '西宁都市网',
            '拉萨都市网',
            '乌鲁木齐都市网',
            '南宁都市网',
            '呼和浩特都市网',
            '银川都市网',
            '北京都市网',
            '天津都市网',
            '上海都市网',
            '重庆都市网',
            '武汉都市网',
            '长沙都市网',
            '南昌都市网',
            '福州都市网',
            '昆明都市网',
            '海口都市网',
            '成都都市网',
            '贵阳都市网',
            '广州都市网',
            '兰州都市网',
            '西安都市网',
            '合肥都市网',
            '南京都市网',
            '杭州都市网',
            '沈阳都市网',
            '长春都市网',
            '哈尔滨都市网',
            '石家庄都市网',
            '太原都市网',
            '郑州都市网',
            '中华城市资讯网',
            '中国科技网',
            '中国娱乐网',
            '中国财经网',
            '财经分享网',
            '科技分享网',
            '娱乐分享网',
            '财经新闻网',
            '科技新闻网',
            '娱乐资讯网',
            '中华资讯网',
            '汽车圈',
            '娱乐讯',
            '科技讯',
            '中华信息网',
            '母婴资讯圈',
            '商业资讯网',
            '未来头条',
            '中国财经头条',
            '热点观察网',
            '中国联播网',
            '中国头条在线',
            '生活分享网',
            '时尚资讯网',
            '趣生活',
            '旅游信息网',
            '游戏资讯网',
            '中商网',
            '财经金融网',
        ],
    ],
    'b2b_package' => [
        'platform_id' => (int) env('MEDIA_DISTRIBUTION_B2B_PACKAGE_PLATFORM_ID', 1),
        'heading' => (string) env('MEDIA_DISTRIBUTION_B2B_PACKAGE_HEADING', 'B2B网站套餐发布'),
        'title' => (string) env('MEDIA_DISTRIBUTION_B2B_PACKAGE_TITLE', '200家B2B网站套餐'),
        'size' => (int) env('MEDIA_DISTRIBUTION_B2B_PACKAGE_SIZE', 200),
        'published_url_type' => (string) env('MEDIA_DISTRIBUTION_B2B_PACKAGE_PUBLISHED_URL_TYPE', 'docs 文档链接'),
        'media_list' => <<<'B2B_MEDIA_LIST'
康保信息港 http://www.dcek.com.cn/20240731/17495230.html
肇源信息港 http://www.bu5.com.cn/20240731/99715721.html
德兴信息网 http://www.temaio.cn/20240731/93570489.html
新余新媒体 http://www.813978.com.cn/20240731/42292699.html
林西百事通 http://www.ktfed.cn/20240731/40810364.html
天极新闻网 http://www.zh1.com.cn/20240731/86136344.html
巢湖新闻网 http://www.phd8.cn/20240731/71115390.html
兴城信息网 http://www.asms.com.cn/20240731/15745913.html
白城信息网 http://www.vco.net.cn/20240731/31520104.html
天长信息网 http://www.lzkkxb.cn/20240731/42548945.html
开原百科网 http://www.4gk.com.cn/20240731/39130625.html
迁安便民网 http://www.qbblm.cn/20240731/78241372.html
广昌百科网 http://www.slexm.cn/20240731/38173211.html
江东百事通 http://www.5msx.cn/20240731/98057715.html
莱州信息港 http://www.lwnfio.cn/20240731/60210047.html
神池百事通 http://www.8mik.cn/20240731/67367022.html
淮南便民网 http://www.kbbjl.cn/20240731/67236016.html
夜猫网 http://www.6xt.com.cn/20240731/76385797.html
突泉资讯网 http://www.elow.com.cn/20240731/57439542.html
运城信息社 http://www.jk86.cn/20240731/84904684.html
泉山新媒体 http://www.3hl.com.cn/20240731/25284949.html
龙湾百科网 http://www.b30.com.cn/20240731/57111222.html
中国商讯网 http://www.cnjsc.cn/20240731/95457183.html
青原新媒体 http://www.zdymn.cn/20240731/18512331.html
起点资讯网 http://www.v38.com.cn/20240731/57208616.html
庆元新闻网 http://www.nimasou.cn/20240731/90395998.html
塞纳网 http://www.40n.com.cn/20240731/05217257.html
衢州便民网 http://www.06306.cn/20240731/17717728.html
漳州生活网 http://www.0754zx.cn/20240731/22799791.html
法库信息港 http://www.dxos.com.cn/20240731/94508301.html
桐城生活网 http://www.lifeled.com.cn/20240731/52210903.html
寿县资讯网 http://www.dtcukm.cn/20240731/05092521.html
粒米供求网 http://www.li51.com.cn/20240731/19727793.html
天宁信息社 http://www.h45.com.cn/20240731/74509003.html
潢川信息社 http://www.suwzq.cn/20240731/73532384.html
北市百事通 http://www.khoth.cn/20240731/77523462.html
荣波网 http://www.rungbo.com.cn/20240731/76901834.html
高淳百事通 http://www.unsv.com.cn/20240731/43240419.html
普利斯信息网 http://www.xepma.com.cn/20240731/83434181.html
依兰新媒体 http://www.awalk.com.cn/20240731/34395533.html
金华便民网 http://www.tadzm.cn/20240731/39685757.html
淮阴信息港 http://www.oicy.com.cn/20240731/47720466.html
汤阴资讯网 http://www.wogs.com.cn/20240731/32419709.html
快讯网 http://www.i26.com.cn/20240731/07204765.html
沈丘信息网 http://www.mb11.cn/20240731/56074015.html
饶河生活网 http://www.59k.com.cn/20240731/99698099.html
广德百科网 http://www.74q.com.cn/20240731/06643816.html
富阳信息社 http://www.ktw6.cn/20240731/92754776.html
通化信息社 http://www.heoper.com.cn/20240731/12292188.html
永乐网 http://www.ig5.com.cn/20240731/72443154.html
环球快讯 http://www.0589.com.cn/20240731/28587830.html
高密新媒体 http://www.vxcei.cn/20240731/85205264.html
宽城便民网 http://www.mptoo.com/20240731/29974665.html
久易新闻网 http://www.91zb.com.cn/20240731/46986315.html
广平信息港 http://www.5xo.cn/20240731/15817713.html
圣都网 http://www.3dc.com.cn/20240731/31536062.html
香坊生活网 http://www.zoart.cn/20240731/22195323.html
龙湖网 http://www.x75.com.cn/20240731/79765855.html
莲都生活网 http://www.dinber.cn/20240731/38341231.html
铁东资讯网 http://www.ec3.com.cn/20240731/56743686.html
武乡百事通 http://www.5bok.cn/20240731/48264937.html
宝清百事通 http://www.z68.com.cn/20240731/16057979.html
泽州便民网 http://www.nt555.cn/20240731/68588785.html
华容百事通 http://www.rituan.com.cn/20240731/52730852.html
淳安新闻网 http://www.c500.com.cn/20240731/20528098.html
泰和网 http://www.tayle.com.cn/20240731/77080632.html
沭阳便民网 http://www.36v.com.cn/20240731/05944492.html
安新百科网 http://www.25xu.cn/20240731/65214006.html
双桥便民网 http://www.stgrrc.cn/20240731/04438126.html
裕华信息港 http://www.cmok.com.cn/20240731/23478196.html
平邑信息网 http://www.ixyjb.cn/20240731/02041564.html
广丰信息港 http://www.rlalcn.cn/20240731/80831700.html
阿拉丁商贸网 http://www.alpchina.com.cn/20240731/17614124.html
串串网 http://www.cnicc.com.cn/20240731/17528323.html
肥乡新媒体 http://www.cha80.cn/20240731/89983889.html
陕县新闻网 http://www.flkrz.cn/20240731/55775797.html
辽阳新媒体 http://www.o25.com.cn/20240731/27835550.html
酬宾网 http://www.cayon.com.cn/20240731/91563685.html
维新网 http://www.winex.com.cn/20240731/97274739.html
惠民商贸网 http://www.scyxl.com.cn/20240731/71325495.html
风尚网 http://www.ct6.com.cn/20240731/85234457.html
秀城百科网 http://www.escok.cn/20240731/52703037.html
廊坊百事通 http://www.dcxgm.cn/20240731/17892229.html
当涂信息网 http://www.kbbcq.cn/20240731/84504241.html
临朐信息港 http://www.fonh.cn/20240731/67140780.html
阜平资讯网 http://www.28ki.cn/20240731/47372479.html
深度热点 http://www.c86.com.cn/20240731/40224790.html
西部视窗 http://www.ab6.com.cn/20240731/65377917.html
靖宇信息港 http://www.markers.cn/20240731/12011955.html
宝坻便民网 http://www.wkc5.com/20240731/80161161.html
平潭百事通 http://www.bcrsg.cn/20240731/60377533.html
接单网 http://www.jomdp.cn/20240731/92181067.html
温岭便民网 http://www.alytb.cn/20240731/43834665.html
任城资讯网 http://www.szlycy.com.cn/20240731/04939026.html
铁锋新媒体 http://www.03l.com.cn/20240731/89092354.html
滕州新媒体 http://www.lzm66.cn/20240731/61681273.html
长岭生活网 http://www.yhd77.cn/20240731/43129680.html
右玉资讯网 http://www.xtyfzs.cn/20240731/75539068.html
南山信息社 http://www.rd5.com.cn/20240731/59333148.html
永吉新闻网 http://www.58un.com.cn/20240731/89287615.html
鼎铭网 http://www.dmtoo.com/20240731/52692707.html
左权百事通 http://www.qlrxo.cn/20240731/20972002.html
福清生活网 http://www.ntzzjj.cn/20240731/35239549.html
隆化百科网 http://www.20ml.cn/20240731/21907191.html
马塘百事通 http://www.lhc958.cn/20240731/06397877.html
宜都生活网 http://www.wqphf.cn/20240731/66545371.html
宏图网 http://www.hntoo.com/20240731/69323774.html
盐湖百事通 http://www.4xa.com.cn/20240731/38838711.html
龙山资讯网 http://www.imwin.cn/20240731/26238220.html
西丰生活网 http://www.femxx.cn/20240731/10192420.html
集贤便民网 http://www.27m.com.cn/20240731/28334236.html
南乐生活网 http://www.hltkx.cn/20240731/09008948.html
芦溪生活网 http://www.hnsxzs.cn/20240731/67877887.html
先锋晚报 http://www.xp6.com.cn/20240731/72726221.html
包头生活网 http://www.2mok.cn/20240731/73881223.html
温州生活网 http://www.x12.com.cn/20240731/14170431.html
宿豫新闻网 http://www.zaxn.com.cn/20240731/59412391.html
烟台便民网 http://www.shchil.com.cn/20240731/36359216.html
无名商贸网 http://www.wm28.cn/20240731/06702877.html
岚县信息网 http://www.ba4.com.cn/20240731/02013459.html
明溪便民网 http://www.xwten.cn/20240731/86244023.html
晋源信息港 http://www.mdgtc.cn/20240731/42858452.html
桦川便民网 http://www.kr2.com.cn/20240731/20595390.html
河津新媒体 http://www.2ar.com.cn/20240731/42837661.html
大田生活网 http://www.umxhe.cn/20240731/83832997.html
虹济新闻网 http://www.2480.com.cn/20240731/72830115.html
祁门信息港 http://www.k861.cn/20240731/45700257.html
玛雅传媒 http://www.2ky.com.cn/20240731/04566632.html
佰企网 http://www.21cx.com.cn/20240731/10198773.html
鹤壁信息社 http://www.ywxyw.cn/20240731/49709587.html
五常百事通 http://www.ox3.com.cn/20240731/21927588.html
靖江新媒体 http://www.wol3.cn/20240731/51848702.html
米粒信息网 http://www.03ml.cn/20240731/37500273.html
临县生活网 http://www.bo51.cn/20240731/55843557.html
沃森网 http://www.volten.cn/20240731/81490430.html
八折网 http://www.8zhe.com.cn/20240731/43044490.html
长泰新闻网 http://www.vxthp.cn/20240731/28051029.html
襄阳新闻网 http://www.vfile.com.cn/20240731/44054117.html
莱山新媒体 http://www.mee7.cn/20240731/68994691.html
泊头信息港 http://www.cktxc.cn/20240731/12858546.html
昌邑信息网 http://www.0627.org/20240731/41442052.html
广陵新闻网 http://www.blao.com.cn/20240731/66005107.html
速推网 http://www.soartech.cn/20240731/13784829.html
兴和网 http://www.xhtoo.com/20240731/51828888.html
博通网 http://www.batol.com.cn/20240731/81109425.html
靖安新闻网 http://www.hljled.com.cn/20240731/46252596.html
泰顺信息社 http://www.ie2.com.cn/20240731/77144039.html
陵川新闻网 http://www.07v.com.cn/20240731/66915707.html
怀远信息港 http://www.qbbql.cn/20240731/66892391.html
锦州信息港 http://www.dubnn.cn/20240731/60823349.html
猎购网 http://www.liegou.net.cn/20240731/78539487.html
瑞星信息网 http://www.arex.com.cn/20240731/59626559.html
智兔网 http://www.91rabbit.cn/20240731/40438339.html
云推商贸网 http://www.anytrack.com.cn/20240731/63391797.html
珲春新媒体 http://www.hzdch.com.cn/20240731/71365499.html
贝尔新闻网 http://www.5et.com.cn/20240731/58264542.html
永康新闻网 http://www.hcxym.cn/20240731/00717383.html
离石信息港 http://www.jgenk.cn/20240731/99053659.html
遵化新闻网 http://www.drled.com.cn/20240731/55191754.html
金湖信息社 http://www.5cpt.com.cn/20240731/19932409.html
常春藤新闻网 http://www.2405.com.cn/20240731/36976196.html
郯城百事通 http://www.pptfa.cn/20240731/29309071.html
青浦信息网 http://www.zd9.com.cn/20240731/47885259.html
兴隆百事通 http://www.kyuju.cn/20240731/25832817.html
任丘新闻网 http://www.6bnk.cn/20240731/75317082.html
荆门生活网 http://www.coptel.com.cn/20240731/56651089.html
临猗生活网 http://www.qbbsy.cn/20240731/73115902.html
宾县百科网 http://www.17lbw.cn/20240731/06135683.html
东山便民网 http://www.chxws.cn/20240731/85329559.html
奎文资讯网 http://www.xshida.com.cn/20240731/63844096.html
胶州信息网 http://www.shm66.cn/20240731/05484745.html
福州信息社 http://www.y796.cn/20240731/91940811.html
金东百事通 http://www.ssie.com.cn/20240731/31465298.html
拓创网 http://www.tc28.com.cn/20240731/29045108.html
麻山百科网 http://www.h94.com.cn/20240731/65302853.html
虹口新闻网 http://www.wm28.com.cn/20240731/35551412.html
凌海新媒体 http://www.lnox.com.cn/20240731/91036728.html
喜得可贸易网 http://www.xideke.com.cn/20240731/80712759.html
李沧新媒体 http://www.qdyhke.com.cn/20240731/25058816.html
保定便民网 http://www.sivmc.cn/20240731/49917001.html
新晨报 http://www.vp5.com.cn/20240731/01359372.html
灵寿生活网 http://www.akx5.com/20240731/79984427.html
金典网 http://www.i688.com.cn/20240731/34488023.html
长治百科网 http://www.5akc.cn/20240731/92180492.html
双塔资讯网 http://www.jolion.com.cn/20240731/59578120.html
速腾信息网 http://www.surtek.com.cn/20240731/53332381.html
阳高百科网 http://www.5adk.cn/20240731/89764847.html
枫林资讯 http://www.fc7.com.cn/20240731/51179657.html
苍南新闻网 http://www.5bd.com.cn/20240731/89044332.html
名录网 http://www.03ml.com.cn/20240731/18369702.html
莱芜新媒体 http://www.uzcof.cn/20240731/72314087.html
小鸭资讯网 http://www.51duck.com/20240731/10954094.html
建邺新媒体 http://www.2jd.com.cn/20240731/88933643.html
崇文网 http://www.w50.com.cn/20240731/29706963.html
红岗百科网 http://www.58mi.com.cn/20240731/33172126.html
嵊泗信息社 http://www.somoy.cn/20240731/27947986.html
知客网 http://www.zukao.com.cn/20240731/44496369.html
舞阳生活网 http://www.hltdh.cn/20240731/36903467.html
洪洞新媒体 http://www.4cpx.cn/20240731/18023883.html
淇县信息网 http://www.yhk777.cn/20240731/85260706.html
B2B_MEDIA_LIST,
    ],
];
