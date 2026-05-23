法令の条文構造と法令XML
本稿では、法令のおおまかな構造について解説し、またそれらの構造が法令標準XMLスキーマを用いたXML（法令XML）でどのように表現されるか、具体例とともに解説します。

その1：本則や附則など
法令はものによっては長く複雑な構造を持ちますが、一定の形式に従って記述されており、典型的なパターンを持っています。まずは、e-Gov法令検索で閲覧できる行政手続における特定の個人を識別するための番号の利用等に関する法律（平成二十五年法律第二十七号）（以下「マイナンバー法」）を例におおまかな構造を見ていきます。

マイナンバー法抜粋（…は注記、2024年1月23日時点）：

平成二十五年法律第二十七号
行政手続における特定の個人を識別するための番号の利用等に関する法律
...
　（目的）
第一条　この法律は、行政機関、地方公共団体その他の行政事務を処理する者が、...
...
　　　附　則
　（施行期日）
第一条　この法律は、公布の日から起算して三年を超えない範囲内において政令で定める日から施行する。ただし、次の各号に掲げる規定は、当該各号に定める日から施行する。
　...
...

1行目: 法令番号です。なお、現行のe-Gov法令検索ではこのように表示していますが、官報では少し書き方が異なります（参考）。
2行目: 法令名です。
6-9行目: 法令の内容である条文です。後述する「附則」と対比して「本則」と呼びます。本則の条文を指すときは、単に「第一条」のように参照します。多くの場合は「条」を基本単位として記述し、「条建て」と呼ばれます。「項」を基本単位とした「項建て」のように他の記述方法を用いる法令もあります。
11-15行目: 「附則」です。法令の施行期日などを記述します。附則の条文を指すときは「附則第一条」のように参照します。
ここまでの法令の階層構造をまとめると下記のようになります。

法令（法令XML全体）
  ├─法令番号
  ├─法令名
  ├─本則
  │   └─条など
  └─附則
      └─条など

法令XMLでの表現
上記「その1」の条文を法令標準XMLスキーマを用いたXML（法令XML）で表現すると下記のようになります。（一部属性や章の構造などは省略しています。）

（…は注記）

<Law>
  <LawNum>平成二十五年法律第二十七号</LawNum>
  <LawBody>
    <LawTitle>行政手続における特定の個人を識別するための番号の利用等に関する法律</LawTitle>
 
    …
 
    <MainProvision>
      …
      <Article Num="1">
        <ArticleCaption>（目的）</ArticleCaption>
        <ArticleTitle>第一条</ArticleTitle>
        <Paragraph Num="1">
          <ParagraphNum/>
          <ParagraphSentence>
            <Sentence Num="1">この法律は、行政機関、地方公共団体その他の行政事務を処理する者が、…</Sentence>
          </ParagraphSentence>
        </Paragraph>
      </Article>
      …
    </MainProvision>
 
    <SupplProvision>
      <SupplProvisionLabel>附　則</SupplProvisionLabel>
      <Article Num="1">
        <ArticleCaption>（施行期日）</ArticleCaption>
        <ArticleTitle>第一条</ArticleTitle>
        <Paragraph Num="1">
          <ParagraphNum/>
          <ParagraphSentence>
            <Sentence Function="main" Num="1">この法律は、公布の日から起算して三年を超えない範囲内において政令で定める日から施行する。</Sentence>
            <Sentence Function="proviso" Num="2">ただし、次の各号に掲げる規定は、当該各号に定める日から施行する。</Sentence>
          </ParagraphSentence>
          …
        </Paragraph>
      </Article>
      …
    </SupplProvision>
 
    …
 
  </LawBody>
</Law>

<Law>要素は法令XMLのルート要素です。
<LawNum>要素は「法令番号」を表します。
<LawBody>要素は法令名や本則、附則などを含む法令の内容全体を表します。
<LawTitle>要素は「法令名」を表します。
<MainProvision>要素は「本則」を表します。
<SupplProvision>要素は「附則」を表します。
<SupplProvisionLabel>要素は「附則の題名」を表します。
Try it out!
サンプルコードの実行方法
法令APIでマイナンバー法の法令XMLを取得し、そこから<LawNum>（法令番号）、<LawTitle>（法令名）、本則の<Article>（条）冒頭3つ、附則の<Article>（条）冒頭3つを取得してみます。

下記のサンプルコードでは、<Article>の取得時に、簡単のため、getElementsByTagNameを使用しています。この場合、意図せずタグの深い階層に入れ子になった<Article>を取得することがあり、これはいわゆる「条の一覧を取得」よりも余計にタグを取得してしまう可能性があるので、実際のアプリ作成時にはご注意ください。

法令XMLから法令番号等を表示
(async () => {
    // 法令APIからマイナンバー法（法令ID "425AC0000000027"）の法令本文XMLを取得する
    const r = await fetch("https://laws.e-gov.go.jp/api/1/lawdata/425AC0000000027");
    const xml = await r.text();
    const parser = new DOMParser();
    const doc = parser.parseFromString(xml, "application/xml");
 
    // LawNum要素を取得する
    const lawNumEl = doc.querySelector("Law > LawNum");
    console.log(lawNumEl);
 
    // LawTitle要素を取得する
    const lawTitleEl = doc.querySelector("Law > LawBody > LawTitle");
    console.log(lawTitleEl);
 
    // MainProvision中のArticleを取得する
    const mainProvisionEl = doc.querySelector("Law > LawBody > MainProvision");
    console.log(mainProvisionEl);
    const mpArticleElList = [...(mainProvisionEl?.getElementsByTagName("Article") ?? [])];
    for (const el of mpArticleElList.slice(0, 3)) console.log(el);
 
    // （AmendLawNum属性を持たない）SupplProvision中のArticleを取得する
    const supplProvisionEl = doc.querySelector("Law > LawBody > SupplProvision:not([AmendLawNum])");
    console.log(supplProvisionEl);
    const spArticleElList = [...(supplProvisionEl?.getElementsByTagName("Article") ?? [])];
    for (const el of spArticleElList.slice(0, 3)) console.log(el);
})();

その2：章や別表など
同じマイナンバー法を用いて、「その1」では略した部分を一部広げて見ていきます。

マイナンバー法抜粋（…は注記、2024年1月23日時点）：

平成二十五年法律第二十七号
行政手続における特定の個人を識別するための番号の利用等に関する法律
目次
　第一章　総則（第一条―第六条）
　第二章　個人番号（第七条―第十六条）
　…
　　　第一章　総則
　（目的）
第一条　この法律は、行政機関、地方公共団体その他の行政事務を処理する者が、…
…
　　　附　則
　（施行期日）
第一条　この法律は、公布の日から起算して三年を超えない範囲内において政令で定める日から施行する。ただし、次の各号に掲げる規定は、当該各号に定める日から施行する。
　…
…
　　　附　則　（平成二四年八月二二日法律第六七号）　抄
…
　　　附　則　（平成二四年一一月二六日法律第一〇二号）　抄
…
別表第一（第九条関係）
…
別表第二（第十九条、第二十一条関係）
…

4-7行目: 目次です。章などの大きな構造を記述する場合が多いです。
9行目: 章名です。章のほかに「編」や「節」などの階層があります。条項をグループ化するために用いられます。
16-22行目（再掲）: 附則です。後述の改正附則に対比して「原始附則」と呼ばれます。
24-28行目: 「改正附則」です。これら改正附則は原始附則とは異なり、現在見ている法令（上記の例ではマイナンバー法）には属さず、改正法令に属します。言い換えると、改正附則は改正法令の原始附則です。そのため、改正附則を指す場合は改正法令の法令番号などを用いて参照します。
30-34行目: 別表です。こちらは現在見ている法令（上記の例ではマイナンバー法）に属し、「別表第一」のように参照します。別表のほかに、別図、別記などのバリエーションがあります。
ここまでの法令の階層構造をまとめると下記のようになります。

法令（法令XML全体）
  ├─法令番号
  ├─法令名
  ├─目次など
  ├─本則
  │   └─章など
  │       └─条など
  ├─原始附則
  │   └─条など
  ├─改正附則
  │   └─条など
  └─別表など

法令XMLでの表現
上記「その2」の条文を法令標準XMLスキーマを用いたXML（法令XML）で表現すると下記のようになります。（一部属性は省略しています。）

（…は注記）

<Law>
  <LawNum>平成二十五年法律第二十七号</LawNum>
  <LawBody>
    <LawTitle>行政手続における特定の個人を識別するための番号の利用等に関する法律</LawTitle>
    <TOC>
      <TOCLabel>目次</TOCLabel>
      <TOCChapter Num="1">
        <ChapterTitle>第一章　総則</ChapterTitle>
        <ArticleRange>（第一条―第六条）</ArticleRange>
      </TOCChapter>
      <TOCChapter Num="2">
        <ChapterTitle>第二章　個人番号</ChapterTitle>
        <ArticleRange>（第七条―第十六条）</ArticleRange>
      </TOCChapter>
      …
    </TOC>
    <MainProvision>
      <Chapter Num="1">
        <ChapterTitle>第一章　総則</ChapterTitle>
        <Article Num="1">
          <ArticleCaption>（目的）</ArticleCaption>
          <ArticleTitle>第一条</ArticleTitle>
          <Paragraph Num="1">
            <ParagraphNum/>
            <ParagraphSentence>
              <Sentence Num="1">この法律は、行政機関、地方公共団体その他の行政事務を処理する者が、…</Sentence>
            </ParagraphSentence>
          </Paragraph>
        </Article>
        …
      </Chapter>
      …
    </MainProvision>
    <SupplProvision>
      <SupplProvisionLabel>附　則</SupplProvisionLabel>
      <Article Num="1">
        <ArticleCaption>（施行期日）</ArticleCaption>
        <ArticleTitle>第一条</ArticleTitle>
        <Paragraph Num="1">
          <ParagraphNum/>
          <ParagraphSentence>
            <Sentence Function="main" Num="1">この法律は、公布の日から起算して三年を超えない範囲内において政令で定める日から施行する。</Sentence>
            <Sentence Function="proviso" Num="2">ただし、次の各号に掲げる規定は、当該各号に定める日から施行する。</Sentence>
          </ParagraphSentence>
          …
        </Paragraph>
      </Article>
      …
    </SupplProvision>
    <SupplProvision AmendLawNum="平成二四年八月二二日法律第六七号" Extract="true">
      <SupplProvisionLabel>附　則</SupplProvisionLabel>
      …
    </SupplProvision>
    <SupplProvision AmendLawNum="平成二四年一一月二六日法律第一〇二号" Extract="true">
      <SupplProvisionLabel>附　則</SupplProvisionLabel>
      …
    </SupplProvision>
    …
    <AppdxTable Num="1">
      <AppdxTableTitle>別表第一</AppdxTableTitle>
      <RelatedArticleNum>（第九条関係）</RelatedArticleNum>
      …
    </AppdxTable>
    <AppdxTable Num="2">
      <AppdxTableTitle>別表第二</AppdxTableTitle>
      <RelatedArticleNum>（第十九条、第二十一条関係）</RelatedArticleNum>
    </AppdxTable>
  </LawBody>
</Law>

<TOC>要素は「目次」を表します。
<TOCLabel>要素は「目次の題名」を表します。
<TOCChapter>要素は目次の項目のうち「章」を表すものです。他にも編や節など、目次の要素を表すタグのバリエーションがあります。
8行目の<ChapterTitle>要素は後述の19行目の<ChapterTitle>と同じタグで、「章の題名」を表します。
<ArticleRange>要素は目次の項目に付記されている「条の範囲」を表します。
<Chapter>要素は「章」を表します。他にも編や節など、条項のグループを表すタグのバリエーションがあります。
19行目の<ChapterTitle>要素は「章の題名」を表します。
（再掲）34行目の<SupplProvision>要素は「附則」（原始附則）を表します。
50行目の<SupplProvision>は改正附則ですが、原始附則も改正附則も同じ<SupplProvision>要素を用います。ただし、改正附則の場合は改正附則が属する改正法令の法令番号をAmendLawNum属性として指定します。ここでの法令番号の形式は通常法令の参照の際に用いられる形式とは異なりますが、年、法令種別、番号の内容は同じものを指しています。また、附則の全部を記述するのではなく抜粋（抄録）する場合はExtract="true"属性を指定します。e-Gov法令検索の画面上は「抄」と表示されます。
<AppdxTable>要素は「別表」を表します。他にも別図や別記など、付属の要素を表すタグのバリエーションがあります。
<AppdxTableTitle>要素は「別表の題名」を表します。
<RelatedArticleNum>要素は別表などに付記されている「関係する条」を表します。
Try it out!
サンプルコードの実行方法
法令APIでマイナンバー法の法令XMLを取得し、そこから本則直下の<Chapter>（章）、原始附則の<SupplProvision>、改正附則の<SupplProvision>のうち冒頭3つ、<AppdxTable>（別表）を取得してみます。

法令XMLから章等を表示
(async () => {
    // 法令APIからマイナンバー法（法令ID "425AC0000000027"）の法令本文XMLを取得する
    const r = await fetch("https://laws.e-gov.go.jp/api/1/lawdata/425AC0000000027");
    const xml = await r.text();
    const parser = new DOMParser();
    const doc = parser.parseFromString(xml, "application/xml");
 
    // MainProvision直下のChapterを取得する
    const chapterElList = [...doc.querySelectorAll("Law > LawBody > MainProvision > Chapter")];
    for (const el of chapterElList) console.log(el);
 
    // 原始附則のSupplProvisionを取得する
    const origSpElList = [...doc.querySelectorAll("Law > LawBody > SupplProvision:not([AmendLawNum])")];
    for (const el of origSpElList) console.log(el);
 
    // 改正附則のSupplProvisionを取得する
    const amendSpElList = [...doc.querySelectorAll("Law > LawBody > SupplProvision[AmendLawNum]")];
    for (const el of amendSpElList.slice(0, 3)) console.log(el);
 
    // AppdxTableを取得する
    const appdxTableElList = [...doc.querySelectorAll("Law > LawBody > AppdxTable")];
    for (const el of appdxTableElList) console.log(el);
})();

その3：条や項など
同じマイナンバー法を用いて、今度は条項の構造を見ていきます。

マイナンバー法抜粋（…は注記、2024年1月23日時点）：

　（個人番号とすべき番号の生成）
第八条　市町村長は、前条第一項又は第二項の規定により…
２　機構は、前項の規定により市町村長から…
　一　他のいずれの個人番号…
　二　前項の住民票コードを…
　三　前号の住民票コードを…
３　機構は、前項の規定により個人番号とすべき番号を…

1行目: 条の見出しです。
2行目: 条の本体です。「第八条」は条名です。なお、この例では条の下位構造として「項」が使用されているため、「第一項」として扱われます。そのため、「市町村長は、…」の条文を指す場合は「第八条第一項」のように参照することになります。
3行目: 項です。条の次の下位構造です。3行目は項番号が「２」なので「第二項」ということになります。条番号を含めた参照は「第八条第二項」です。なお、この項は下位要素として「号」を含んでいますが、下位要素以外の部分（ここでは3行目の文章）を「柱書」と呼ぶことがあります。
4行目: 号です。項の次の下位構造です。4行目は号名が「一」なので「第一号」ということになります。条番号を含めた参照は「第八条第二項第一号」です。
項のない条（一項建ての条）の場合は条の直下に号が付くことがあります。この場合は「第○条第○号」のように参照することとなります。ただし、この場合でも、法令XML上は項番号の無いParagraph要素が使用されます。

条項の階層構造は、「条」→「項」→「号」のように細かくなります。「号」のさらに下位構造もあり、「号の細分」のように呼ばれます。

「条」や「号」には、「枝番号」を付すことができます。例えば、「第二十一条の二」のような条名にすることができます。枝番号は、元の条名とは入れ子構造の関係にはなく、「第二十一条の二」は、「第二十一条」とは独立した、まったく別の条です。枝番号をさらに深くして、「第三十八条の三の二」のようにすることもできます。この場合でも、「第三十八条」や「第三十八条の三」とは独立した、まったく別の条です。

条や項に関する階層構造をまとめると下記のようになります。

条
  ├─条見出し
  ├─条名
  └─項
      ├─項番号（第二項以降の場合）
      ├─項の柱書
      └─号
          ├─号名
          ├─号の柱書
          └─号の細分
              └─…

法令XMLでの表現
上記「その3」の条文を法令標準XMLスキーマを用いたXML（法令XML）で表現すると下記のようになります。（一部属性や上位構造は省略しています。）

（…は注記）

<Article Num="8">
  <ArticleCaption>（個人番号とすべき番号の生成）</ArticleCaption>
  <ArticleTitle>第八条</ArticleTitle>
  <Paragraph Num="1">
    <ParagraphNum/>
    <ParagraphSentence>
      <Sentence Num="1">市町村長は、前条第一項又は第二項の規定により…</Sentence>
    </ParagraphSentence>
  </Paragraph>
  <Paragraph Num="2">
    <ParagraphNum>２</ParagraphNum>
    <ParagraphSentence>
      <Sentence Num="1">機構は、前項の規定により市町村長から…</Sentence>
    </ParagraphSentence>
    <Item Num="1">
      <ItemTitle>一</ItemTitle>
      <ItemSentence>
        <Sentence Num="1">他のいずれの個人番号…</Sentence>
      </ItemSentence>
    </Item>
    <Item Num="2">
      <ItemTitle>二</ItemTitle>
      <ItemSentence>
        <Sentence Num="1">前項の住民票コードを…</Sentence>
      </ItemSentence>
    </Item>
    <Item Num="3">
      <ItemTitle>三</ItemTitle>
      <ItemSentence>
        <Sentence Num="1">前号の住民票コードを…</Sentence>
      </ItemSentence>
    </Item>
  </Paragraph>
  <Paragraph Num="3">
    <ParagraphNum>３</ParagraphNum>
    <ParagraphSentence>
      <Sentence Num="1">機構は、前項の規定により個人番号とすべき番号を…</Sentence>
    </ParagraphSentence>
  </Paragraph>
</Article>

<Article>要素は「条」を表します。
<ArticleCaption>要素は「条の見出し」を表します。
<ArticleTitle>要素は「条名」を表します。
<Paragraph>要素は「項」を表します。なお、一項建ての条の場合でも、項番号を省略した項が一つあるとみなして<Paragraph>要素を含め、その要素の中に本文を記述します。
5行目の<ParagraphNum>要素は「項番号」を表しますが、条に含まれる第一項の項番号は省略するので、その場合は<ParagraphNum>要素は空のものを指定します。
<ParagraphSentence>要素は「項の柱書」を表します。なお、「柱書」は、ここでは下位要素を含まない「項」直下の文章を指します。
<Sentence>要素は文章を表します。
11行目の<ParagraphNum>要素は「項番号」を表します。
<Item>要素は「号」を表します。
<ItemTitle>要素は「号名」を表します。
<ItemSentence>要素は「号の柱書」を表します。
<Article>要素や<Paragraph>要素、<Item>要素にはNum属性があり、条名、項番号、号名に対応した番号が格納されています。枝番号の場合は、アンダースコアでつないで、例えば「第三十八条の三の二」の場合は、Num="38_3_2"のようにします。

Try it out!
サンプルコードの実行方法
法令APIでマイナンバー法の法令XMLを取得し、そこから第八条を取り出して条項を出力してみます。

下記のサンプルコードでは、<Article>や<Paragraph>などの要素の取得時に、簡単のため、getElementsByTagNameやquerySelectorの子孫結合子を使用しています。この場合、意図せずタグの深い階層に入れ子になったタグを取得してしまう可能性があるので、実際のアプリ作成時にはご注意ください。

法令XMLから条項を表示
(async () => {
    // 法令APIからマイナンバー法（法令ID "425AC0000000027"）の法令本文XMLを取得する
    const r = await fetch("https://laws.e-gov.go.jp/api/1/lawdata/425AC0000000027");
    const xml = await r.text();
    const parser = new DOMParser();
    const doc = parser.parseFromString(xml, "application/xml");
 
    // 出力する行の配列
    const lines = [];
 
    // 第八条のArticleを取得
    const articleEl = doc.querySelector('Law > LawBody > MainProvision Article[Num="8"]');
    const articleCaptionEl = articleEl?.getElementsByTagName("ArticleCaption")[0];
    if (articleCaptionEl) lines.push(`　${articleCaptionEl.textContent?.trim() ?? ""}`)
    const articleTitleEl = articleEl?.getElementsByTagName("ArticleTitle")[0];
 
    // Paragraphを処理
    for (const [pi, paragraphEl] of [...(articleEl?.getElementsByTagName("Paragraph") ?? [])].entries()) {
        const paragraphNumEl = paragraphEl?.getElementsByTagName("ParagraphNum")[0];
        const paragraphSentenceEl = paragraphEl?.getElementsByTagName("ParagraphSentence")[0];
        lines.push(`${pi === 0 ? articleTitleEl?.textContent?.trim() : ""}${paragraphNumEl?.textContent?.trim() ?? ""}　${paragraphSentenceEl.textContent?.trim() ?? ""}`)
 
        // Itemを処理
        for (const itemEl of [...paragraphEl.getElementsByTagName("Item")]) {
            const itemTitleEl = itemEl?.getElementsByTagName("ItemTitle")[0];
            const itemSentenceEl = itemEl?.getElementsByTagName("ItemSentence")[0];
            lines.push(`　${itemTitleEl?.textContent?.trim() ?? ""}　${itemSentenceEl.textContent?.trim() ?? ""}`)
        }
    }
 
    console.log(lines.join("\n"))
})();




法令標準XMLスキーマ
法令XMLは、「法令標準XMLスキーマ」と呼ばれるXMLスキーマ（XSD）に基づいて作成されています。

法令標準XMLスキーマには、法令XMLに登場するタグの種類や、そのタグの属性や子要素の種類が定義されています。

このページでは、それぞれのタグや属性の意味を解説します。

Law及びトップレベルの要素
<Law>
法令XMLのルート要素です。法令の基本情報の属性を持っています。

子要素: <LawNum> | <LawBody>

属性:

Era(required): "Meiji" | "Taisho" | "Showa" | "Heisei" | "Reiwa"
法令番号に含まれる元号です。

Year(required): positiveInteger
法令番号に含まれる年号です。

Num(required): positiveInteger
法令番号に含まれる番号です。

PromulgateMonth: positiveInteger
公布の月です。

PromulgateDay: positiveInteger
公布の日です。

LawType(required): "Constitution" | "Act" | "CabinetOrder" | "ImperialOrder" | "MinisterialOrdinance" | "Rule" | "Misc"
法令の種別です。

Lang(required): "ja" | "en"
多言語対応を想定した属性です。e-Gov法令検索では"ja"が使用されています。

<LawNum>
法令番号を表す要素です。

子要素: string

<LawBody>
法令本体を表す要素です。

子要素: <LawTitle> | <EnactStatement> | <TOC> | <Preamble> | <MainProvision> | <SupplProvision> | <AppdxTable> | <AppdxNote> | <AppdxStyle> | <Appdx> | <AppdxFig> | <AppdxFormat>

属性:

Subject: string
件名を表します。題名のない法令への対応を想定して設けられた要素ですが、現在は基本的に件名を題名（<LawTitle>）に登録している法令がほとんどです。

書き出しの要素
<LawTitle>
法令の題名（法令名）を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

属性:

Kana: string
法令名の読み（ひらがな）です。

Abbrev: string
法令の略称です。複数ある場合は","で区切って入力されます。

AbbrevKana: string
法令の略称の読み（ひらがな）です。複数ある場合は","で区切って入力されます。

<EnactStatement>
制定文を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<Preamble>
前文を表す要素です。

子要素: <Paragraph>

目次
<TOC>
目次を表す要素です。

子要素: <TOCLabel> | <TOCPreambleLabel> | <TOCPart> | <TOCChapter> | <TOCSection> | <TOCArticle> | <TOCSupplProvision> | <TOCAppdxTableLabel>

<TOCLabel>
目次のラベルを表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<TOCPreambleLabel>
目次中の「前文」の項目を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<TOCPart>
目次中の「編」の項目を表す要素です。

子要素: <PartTitle> | <ArticleRange> | <TOCChapter>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

<TOCChapter>
目次中の「章」の項目を表す要素です。

子要素: <ChapterTitle> | <ArticleRange> | <TOCSection>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

<TOCSection>
目次中の「節」の項目を表す要素です。

子要素: <SectionTitle> | <ArticleRange> | <TOCSubsection> | <TOCDivision>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

<TOCSubsection>
目次中の「款」の項目を表す要素です。

子要素: <SubsectionTitle> | <ArticleRange> | <TOCDivision>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

<TOCDivision>
目次中の「⽬」を表す要素です。

子要素: <DivisionTitle> | <ArticleRange>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

<TOCArticle>
目次中の「条」の項目を表す要素です。

子要素: <ArticleTitle> | <ArticleCaption>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

<TOCSupplProvision>
目次中の「附則」の項目を表す要素です。

子要素: <SupplProvisionLabel> | <ArticleRange> | <TOCArticle> | <TOCChapter>

<TOCAppdxTableLabel>
目次中の「別表」の項目を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<ArticleRange>
目次中の項目に付記される条範囲を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

本則及び附則
<MainProvision>
本則を表す要素です。

子要素: <Part> | <Chapter> | <Section> | <Article> | <Paragraph>

属性:

Extract: boolean
抄録（一部を抜粋して収録）している場合はtrueを指定します。

<SupplProvision>
附則を表す要素です。

子要素: <SupplProvisionLabel> | <Chapter> | <Article> | <Paragraph> | <SupplProvisionAppdxTable> | <SupplProvisionAppdxStyle> | <SupplProvisionAppdx>

属性:

Type: "New" | "Amend"
制定時の場合は"New"、改正時の場合は"Amend"を指定します。

AmendLawNum: string
改正附則が属する改正法令の番号を指定します。

Extract: boolean
抄録（一部を抜粋して収録）している場合はtrueを指定します。

<SupplProvisionLabel>
附則のラベルを表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

章など
<Part>
「編」を表す要素です。

子要素: <PartTitle> | <Article> | <Chapter>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

Hide(default: false): boolean
項目が非表示である場合にtrueを指定します。

<PartTitle>
「編」の題名を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<Chapter>
「章」を表す要素です。

子要素: <ChapterTitle> | <Article> | <Section>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

Hide(default: false): boolean
項目が非表示である場合にtrueを指定します。

<ChapterTitle>
「章名」を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<Section>
「節」を表す要素です。

子要素: <SectionTitle> | <Article> | <Subsection> | <Division>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

Hide(default: false): boolean
項目が非表示である場合にtrueを指定します。

<SectionTitle>
「節名」を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<Subsection>
「款」を表す要素です。

子要素: <SubsectionTitle> | <Article> | <Division>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

Hide(default: false): boolean
項目が非表示である場合にtrueを指定します。

<SubsectionTitle>
「款名」を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<Division>
「目」を表す要素です。

子要素: <DivisionTitle> | <Article>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

Hide(default: false): boolean
項目が非表示である場合にtrueを指定します。

<DivisionTitle>
「目名」を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

条
<Article>
「条」を表す要素です。

子要素: <ArticleCaption> | <ArticleTitle> | <Paragraph> | <SupplNote>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

Hide(default: false): boolean
項目が非表示である場合にtrueを指定します。

<ArticleTitle>
「条名」を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<ArticleCaption>
条見出しを表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

属性:

CommonCaption: boolean
「共通見出し」である場合はtrueを指定します。

項・号・号の細分
<Paragraph>
「項」を表す要素です。

子要素: <ParagraphCaption> | <ParagraphNum> | <ParagraphSentence> | <AmendProvision> | <Class> | <TableStruct> | <FigStruct> | <StyleStruct> | <Item> | <List>

属性:

Num(required): positiveInteger
番号です。

OldStyle(default: false): boolean
項の初字位置が古い形式である場合はtrueを指定します。

OldNum(default: false): boolean
項番号のない古い形式である場合はtrueを指定します。

Hide(default: false): boolean
項目が非表示である場合にtrueを指定します。

<ParagraphCaption>
項見出しを表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

属性:

CommonCaption: boolean
「共通見出し」である場合はtrueを指定します。

<ParagraphNum>
項番号を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<ParagraphSentence>
項の文章（柱書）を表す要素です。

子要素: <Sentence>

<Item>
「号」を表す要素です。

子要素: <ItemTitle> | <ItemSentence> | <Subitem1> | <TableStruct> | <FigStruct> | <StyleStruct> | <List>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

Hide(default: false): boolean
項目が非表示である場合にtrueを指定します。

<ItemTitle>
「号名」を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<ItemSentence>
号の文章（柱書）を表す要素です。

子要素: <Sentence> | <Column> | <Table>

<Subitem1>
「号の細分」（1階層目）を表す要素です。

子要素: <Subitem1Title> | <Subitem1Sentence> | <Subitem2> | <TableStruct> | <FigStruct> | <StyleStruct> | <List>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

Hide(default: false): boolean
項目が非表示である場合にtrueを指定します。

<Subitem1Title>
「号の細分名」（1階層目）を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<Subitem1Sentence>
号の細分（1階層目）の文章（柱書）を表す要素です。

子要素: <Sentence> | <Column> | <Table>

<Subitem2>
「号の細分」（2階層目）を表す要素です。

子要素: <Subitem2Title> | <Subitem2Sentence> | <Subitem3> | <TableStruct> | <FigStruct> | <StyleStruct> | <List>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

Hide(default: false): boolean
項目が非表示である場合にtrueを指定します。

<Subitem2Title>
「号の細分名」（2階層目）を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<Subitem2Sentence>
号の細分（2階層目）の文章（柱書）を表す要素です。

子要素: <Sentence> | <Column> | <Table>

<Subitem3>
「号の細分」（3階層目）を表す要素です。

子要素: <Subitem3Title> | <Subitem3Sentence> | <Subitem4> | <TableStruct> | <FigStruct> | <StyleStruct> | <List>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

Hide(default: false): boolean
項目が非表示である場合にtrueを指定します。

<Subitem3Title>
「号の細分名」（3階層目）を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<Subitem3Sentence>
号の細分（3階層目）の文章（柱書）を表す要素です。

子要素: <Sentence> | <Column> | <Table>

<Subitem4>
「号の細分」（4階層目）を表す要素です。

子要素: <Subitem4Title> | <Subitem4Sentence> | <Subitem5> | <TableStruct> | <FigStruct> | <StyleStruct> | <List>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

Hide(default: false): boolean
項目が非表示である場合にtrueを指定します。

<Subitem4Title>
「号の細分名」（4階層目）を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<Subitem4Sentence>
号の細分（4階層目）の文章（柱書）を表す要素です。

子要素: <Sentence> | <Column> | <Table>

<Subitem5>
「号の細分」（5階層目）を表す要素です。

子要素: <Subitem5Title> | <Subitem5Sentence> | <Subitem6> | <TableStruct> | <FigStruct> | <StyleStruct> | <List>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

Hide(default: false): boolean
項目が非表示である場合にtrueを指定します。

<Subitem5Title>
「号の細分名」（5階層目）を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<Subitem5Sentence>
号の細分（5階層目）の文章（柱書）を表す要素です。

子要素: <Sentence> | <Column> | <Table>

<Subitem6>
「号の細分」（6階層目）を表す要素です。

子要素: <Subitem6Title> | <Subitem6Sentence> | <Subitem7> | <TableStruct> | <FigStruct> | <StyleStruct> | <List>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

Hide(default: false): boolean
項目が非表示である場合にtrueを指定します。

<Subitem6Title>
「号の細分名」（6階層目）を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<Subitem6Sentence>
号の細分（6階層目）の文章（柱書）を表す要素です。

子要素: <Sentence> | <Column> | <Table>

<Subitem7>
「号の細分」（7階層目）を表す要素です。

子要素: <Subitem7Title> | <Subitem7Sentence> | <Subitem8> | <TableStruct> | <FigStruct> | <StyleStruct> | <List>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

Hide(default: false): boolean
項目が非表示である場合にtrueを指定します。

<Subitem7Title>
「号の細分名」（7階層目）を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<Subitem7Sentence>
号の細分（7階層目）の文章（柱書）を表す要素です。

子要素: <Sentence> | <Column> | <Table>

<Subitem8>
「号の細分」（8階層目）を表す要素です。

子要素: <Subitem8Title> | <Subitem8Sentence> | <Subitem9> | <TableStruct> | <FigStruct> | <StyleStruct> | <List>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

Hide(default: false): boolean
項目が非表示である場合にtrueを指定します。

<Subitem8Title>
「号の細分名」（8階層目）を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<Subitem8Sentence>
号の細分（8階層目）の文章（柱書）を表す要素です。

子要素: <Sentence> | <Column> | <Table>

<Subitem9>
「号の細分」（9階層目）を表す要素です。

子要素: <Subitem9Title> | <Subitem9Sentence> | <Subitem10> | <TableStruct> | <FigStruct> | <StyleStruct> | <List>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

Hide(default: false): boolean
項目が非表示である場合にtrueを指定します。

<Subitem9Title>
「号の細分名」（9階層目）を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<Subitem9Sentence>
号の細分（9階層目）の文章（柱書）を表す要素です。

子要素: <Sentence> | <Column> | <Table>

<Subitem10>
「号の細分」（10階層目）を表す要素です。

子要素: <Subitem10Title> | <Subitem10Sentence> | <TableStruct> | <FigStruct> | <StyleStruct> | <List>

属性:

Num(required): string
番号です。

Delete(default: false): boolean
項目が効力を有さないものとして削除扱いとされている場合にtrueを指定します。

Hide(default: false): boolean
項目が非表示である場合にtrueを指定します。

<Subitem10Title>
「号の細分名」（10階層目）を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<Subitem10Sentence>
号の細分（10階層目）の文章（柱書）を表す要素です。

子要素: <Sentence> | <Column> | <Table>

条文
<Sentence>
条文を表す要素です。「前段」「後段」「本文」「ただし書」などの部分ごとに要素を分けます。

子要素: <Line> | <QuoteStruct> | <ArithFormula> | <Ruby> | <Sup> | <Sub> | string

属性:

Num: positiveInteger
番号です。

Function: "main" | "proviso"
「本文」の場合は"main"、「ただし書」の場合は"proviso"を指定します。

Indent: "Paragraph" | "Item" | "Subitem1" | "Subitem2" | "Subitem3" | "Subitem4" | "Subitem5" | "Subitem6" | "Subitem7" | "Subitem8" | "Subitem9" | "Subitem10"
表内での表記でインデント調整が必要な場合に指定します。

WritingMode(default: vertical): "vertical" | "horizontal"
行送り方向です。縦書きの場合は"vertical"、横書きの場合は"horizontal"を指定します。

<Column>
条文が空白で区切られている場合に、それぞれの部分を表す要素です。

子要素: <Sentence>

属性:

Num: positiveInteger
番号です。

LineBreak(default: false): boolean
改行がある場合は"true"を指定します。

Align: "left" | "center" | "right" | "justify"
文字揃えの位置を指定します。

列記
<List>
列記を表す要素です。

子要素: <ListSentence> | <Sublist1>

<ListSentence>
列記の条文を表す要素です。

子要素: <Sentence> | <Column>

<Sublist1>
列記の細分（1段階目）を表す要素です。

子要素: <Sublist1Sentence> | <Sublist2>

<Sublist1Sentence>
列記の細分（1段階目）の条文を表す要素です。

子要素: <Sentence> | <Column>

<Sublist2>
列記の細分（2段階目）を表す要素です。

子要素: <Sublist2Sentence> | <Sublist3>

<Sublist2Sentence>
列記の細分（2段階目）の条文を表す要素です。

子要素: <Sentence> | <Column>

<Sublist3>
列記の細分（3段階目）を表す要素です。

子要素: <Sublist3Sentence>

<Sublist3Sentence>
列記の細分（3段階目）の条文を表す要素です。

子要素: <Sentence> | <Column>

類
<Class>
「類」を表す要素です。「類」は、廃止された家事裁判法（昭和二十二年法律第百五十二号）で用いられていた構造です。

子要素: <ClassTitle> | <ClassSentence> | <Item>

属性:

Num(required): string
<ClassTitle>
「類名」を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<ClassSentence>
「類文」を表す要素です。

子要素: <Sentence> | <Column> | <Table>

インライン要素
<QuoteStruct>
改行を含む構造の引用を表す要素です。例えば、「図として捉える改正」などで使用されます。

子要素: any

<Ruby>
ルビ付きの文字列を表す要素です。

子要素: <Rt> | string

<Rt>
ルビの部分を表す要素です。

子要素: string

<Line>
傍線を表す要素です。

子要素: <QuoteStruct> | <ArithFormula> | <Ruby> | <Sup> | <Sub> | string

属性:

Style(default: solid): "dotted" | "double" | "none" | "solid"
傍線のスタイルを表す要素です。

<Sup>
上付き文字を表す要素です。

子要素: string

<Sub>
下付き文字を表す要素です。

子要素: string

表
<TableStruct>
表項目を表す要素です。

子要素: <TableStructTitle> | <Remarks> | <Table>

<TableStructTitle>
表項目名を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

属性:

WritingMode(default: vertical): "vertical" | "horizontal"
行送り方向です。縦書きの場合は"vertical"、横書きの場合は"horizontal"を指定します。

<Table>
表を表す要素です。

子要素: <TableHeaderRow> | <TableRow>

属性:

WritingMode(default: vertical): "vertical" | "horizontal"
行送り方向です。縦書きの場合は"vertical"、横書きの場合は"horizontal"を指定します。

<TableRow>
表の行（項）を表す要素です。

子要素: <TableColumn>

<TableHeaderRow>
表の欄名の行（項）を表す要素です。

子要素: <TableHeaderColumn>

<TableHeaderColumn>
表の欄名の列（欄）を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<TableColumn>
表の列（欄）を表す要素です。

子要素: <Part> | <Chapter> | <Section> | <Subsection> | <Division> | <Article> | <Paragraph> | <Item> | <Subitem1> | <Subitem2> | <Subitem3> | <Subitem4> | <Subitem5> | <Subitem6> | <Subitem7> | <Subitem8> | <Subitem9> | <Subitem10> | <FigStruct> | <Remarks> | <Sentence> | <Column>

属性:

BorderTop(default: solid): "solid" | "none" | "dotted" | "double"
上の枠線スタイルを表す要素です。

BorderBottom(default: solid): "solid" | "none" | "dotted" | "double"
下の枠線スタイルを表す要素です。

BorderLeft(default: solid): "solid" | "none" | "dotted" | "double"
左の枠線スタイルを表す要素です。

BorderRight(default: solid): "solid" | "none" | "dotted" | "double"
右の枠線スタイルを表す要素です。

rowspan: string
行（項）の方向の結合数を指定します。

colspan: string
行（項）の方向の結合数を指定します。

Align: "left" | "center" | "right" | "justify"
行（項）の方向の位置を指定します。

Valign: "top" | "middle" | "bottom"
列（欄）の方向の位置を指定します。

図
<FigStruct>
図項目を表す要素です。

子要素: <FigStructTitle> | <Remarks> | <Fig>

<FigStructTitle>
図項目名を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<Fig>
図を表す要素です。

子要素:

属性:

src(required): string
図の参照URIを指定します。

算式
<ArithFormula>
算式を表す要素です。

子要素: string

属性:

Num: positiveInteger
番号です。

改正規定
<AmendProvision>
改正規定を表す要素です。

子要素: <AmendProvisionSentence> | <NewProvision>

<AmendProvisionSentence>
改正規定文を表す要素です。

子要素: <Sentence>

<NewProvision>
改正規定中の新規条文を表す要素です。

子要素: <LawTitle> | <Preamble> | <TOC> | <Part> | <PartTitle> | <Chapter> | <ChapterTitle> | <Section> | <SectionTitle> | <Subsection> | <SubsectionTitle> | <Division> | <DivisionTitle> | <Article> | <SupplNote> | <Paragraph> | <Item> | <Subitem1> | <Subitem2> | <Subitem3> | <Subitem4> | <Subitem5> | <Subitem6> | <Subitem7> | <Subitem8> | <Subitem9> | <Subitem10> | <List> | <Sentence> | <AmendProvision> | <AppdxTable> | <AppdxNote> | <AppdxStyle> | <Appdx> | <AppdxFig> | <AppdxFormat> | <SupplProvisionAppdxStyle> | <SupplProvisionAppdxTable> | <SupplProvisionAppdx> | <TableStruct> | <TableRow> | <TableColumn> | <FigStruct> | <NoteStruct> | <StyleStruct> | <FormatStruct> | <Remarks> | <LawBody>

様式等
<NoteStruct>
記項目を表す要素です。

子要素: <NoteStructTitle> | <Remarks> | <Note>

<NoteStructTitle>
記項目名を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<Note>
「記」を表す要素です。

子要素: any

<StyleStruct>
様式項目を表す要素です。

子要素: <StyleStructTitle> | <Remarks> | <Style>

<StyleStructTitle>
様式項目名を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<Style>
様式を表す要素です。

子要素: any

<FormatStruct>
書式項目を表す要素です。

子要素: <FormatStructTitle> | <Remarks> | <Format>

<FormatStructTitle>
書式項目名を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<Format>
書式を表す要素です。

子要素: any

別表等
<AppdxTable>
別表を表す要素です。

子要素: <AppdxTableTitle> | <RelatedArticleNum> | <TableStruct> | <Item> | <Remarks>

属性:

Num: positiveInteger
番号です。

<AppdxTableTitle>
別表名を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

属性:

WritingMode(default: vertical): "vertical" | "horizontal"
行送り方向です。縦書きの場合は"vertical"、横書きの場合は"horizontal"を指定します。

<AppdxNote>
別記を表す要素です。

子要素: <AppdxNoteTitle> | <RelatedArticleNum> | <NoteStruct> | <FigStruct> | <TableStruct> | <Remarks>

属性:

Num: positiveInteger
番号です。

<AppdxNoteTitle>
別記名を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

属性:

WritingMode(default: vertical): "vertical" | "horizontal"
行送り方向です。縦書きの場合は"vertical"、横書きの場合は"horizontal"を指定します。

<AppdxStyle>
別記様式を表す要素です。

子要素: <AppdxStyleTitle> | <RelatedArticleNum> | <StyleStruct> | <Remarks>

属性:

Num: positiveInteger
番号です。

<AppdxStyleTitle>
別記様式名を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

属性:

WritingMode(default: vertical): "vertical" | "horizontal"
行送り方向です。縦書きの場合は"vertical"、横書きの場合は"horizontal"を指定します。

<AppdxFormat>
別記書式を表す要素です。

子要素: <AppdxFormatTitle> | <RelatedArticleNum> | <FormatStruct> | <Remarks>

属性:

Num: positiveInteger
番号です。

<AppdxFormatTitle>
別記書式名を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

属性:

WritingMode(default: vertical): "vertical" | "horizontal"
行送り方向です。縦書きの場合は"vertical"、横書きの場合は"horizontal"を指定します。

<Appdx>
付録を表す要素です。

子要素: <ArithFormulaNum> | <RelatedArticleNum> | <ArithFormula> | <Remarks>

<ArithFormulaNum>
算式番号を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

<AppdxFig>
別図を表す要素です。

子要素: <AppdxFigTitle> | <RelatedArticleNum> | <FigStruct> | <TableStruct>

属性:

Num: positiveInteger
番号です。

<AppdxFigTitle>
別図名を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

属性:

WritingMode(default: vertical): "vertical" | "horizontal"
行送り方向です。縦書きの場合は"vertical"、横書きの場合は"horizontal"を指定します。

<RelatedArticleNum>
関係条文番号を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

附則別表等
<SupplProvisionAppdxTable>
附則別表を表す要素です。

子要素: <SupplProvisionAppdxTableTitle> | <RelatedArticleNum> | <TableStruct>

属性:

Num: positiveInteger
番号です。

<SupplProvisionAppdxTableTitle>
附則別表名を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

属性:

WritingMode(default: vertical): "vertical" | "horizontal"
行送り方向です。縦書きの場合は"vertical"、横書きの場合は"horizontal"を指定します。

<SupplProvisionAppdxStyle>
附則様式を表す要素です。

子要素: <SupplProvisionAppdxStyleTitle> | <RelatedArticleNum> | <StyleStruct>

属性:

Num: positiveInteger
番号です。

<SupplProvisionAppdxStyleTitle>
附則様式名を表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

属性:

WritingMode(default: vertical): "vertical" | "horizontal"
行送り方向です。縦書きの場合は"vertical"、横書きの場合は"horizontal"を指定します。

<SupplProvisionAppdx>
附則付録を表す要素です。

子要素: <ArithFormulaNum> | <RelatedArticleNum> | <ArithFormula>

属性:

Num: positiveInteger
番号です。

備考・付記
<Remarks>
備考を表す要素です。

子要素: <RemarksLabel> | <Item> | <Sentence>

<RemarksLabel>
備考ラベルを表す要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

属性:

LineBreak(default: false): boolean
改行がある場合は"true"を指定します。

<SupplNote>
付記を表す要素です。道路交通法（昭和三十五年法律第百五号）の（罰則 〇〇〇〇）のために設けられた要素です。

子要素: <Line> | <Ruby> | <Sup> | <Sub> | string

# 法令標準XMLスキーマ

<?xml version="1.0" encoding="UTF-8"?>
<!--
    XMLSchema for Japanese Law
    Version: 3.0
    Date: Nov 24, 2020
    Contact: Ministry of Internal Affairs and Communications, Government of JAPAN
-->
<!--  Law =================================================================== -->
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" elementFormDefault="qualified">
  <xs:element name="Law">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="LawNum"/>
        <xs:element ref="LawBody"/>
      </xs:sequence>
      <xs:attribute name="Era" use="required">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="Meiji"/>
            <xs:enumeration value="Taisho"/>
            <xs:enumeration value="Showa"/>
            <xs:enumeration value="Heisei"/>
            <xs:enumeration value="Reiwa"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
      <xs:attribute name="Year" use="required" type="xs:positiveInteger"/>
      <xs:attribute name="Num" use="required" type="xs:positiveInteger"/>
      <xs:attribute name="PromulgateMonth" type="xs:positiveInteger"/>
      <xs:attribute name="PromulgateDay" type="xs:positiveInteger"/>
      <xs:attribute name="LawType" use="required">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="Constitution"/>
            <xs:enumeration value="Act"/>
            <xs:enumeration value="CabinetOrder"/>
            <xs:enumeration value="ImperialOrder"/>
            <xs:enumeration value="MinisterialOrdinance"/>
            <xs:enumeration value="Rule"/>
            <xs:enumeration value="Misc"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
      <xs:attribute name="Lang" use="required">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="ja"/>
            <xs:enumeration value="en"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
    </xs:complexType>
  </xs:element>
  <xs:element name="LawNum" type="xs:string"/>
  <!--  LawBody =============================================================== -->
  <xs:element name="LawBody">
    <xs:complexType>
      <xs:sequence>
        <xs:choice>
          <xs:sequence>
            <xs:element ref="LawTitle"/>
            <xs:element minOccurs="0" maxOccurs="unbounded" ref="EnactStatement"/>
            <xs:element minOccurs="0" ref="TOC"/>
          </xs:sequence>
          <xs:sequence>
            <xs:element maxOccurs="unbounded" ref="EnactStatement"/>
            <xs:element minOccurs="0" ref="TOC"/>
          </xs:sequence>
          <xs:sequence>
            <xs:element ref="TOC"/>
            <xs:element minOccurs="0" ref="LawTitle"/>
          </xs:sequence>
        </xs:choice>
        <xs:element minOccurs="0" ref="Preamble"/>
        <xs:element ref="MainProvision"/>
        <xs:choice minOccurs="0" maxOccurs="unbounded">
          <xs:element ref="SupplProvision"/>
          <xs:element ref="AppdxTable"/>
          <xs:element ref="AppdxNote"/>
          <xs:element ref="AppdxStyle"/>
          <xs:element ref="Appdx"/>
          <xs:element ref="AppdxFig"/>
          <xs:element ref="AppdxFormat"/>
        </xs:choice>
      </xs:sequence>
      <xs:attribute name="Subject"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="LawTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
      <xs:attribute name="Kana"/>
      <xs:attribute name="Abbrev"/>
      <xs:attribute name="AbbrevKana"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="EnactStatement">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  TOC ==================================================================== -->
  <xs:element name="TOC">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="TOCLabel"/>
        <xs:element minOccurs="0" ref="TOCPreambleLabel"/>
        <xs:choice>
          <xs:element maxOccurs="unbounded" ref="TOCPart"/>
          <xs:element maxOccurs="unbounded" ref="TOCChapter"/>
          <xs:element maxOccurs="unbounded" ref="TOCSection"/>
          <xs:element maxOccurs="unbounded" ref="TOCArticle"/>
        </xs:choice>
        <xs:element minOccurs="0" ref="TOCSupplProvision"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="TOCAppdxTableLabel"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
  <xs:element name="TOCLabel">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="TOCPreambleLabel">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="TOCPart">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="PartTitle"/>
        <xs:element minOccurs="0" ref="ArticleRange"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="TOCChapter"/>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="TOCChapter">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="ChapterTitle"/>
        <xs:element minOccurs="0" ref="ArticleRange"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="TOCSection"/>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="TOCSection">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="SectionTitle"/>
        <xs:element minOccurs="0" ref="ArticleRange"/>
        <xs:choice minOccurs="0" maxOccurs="unbounded">
          <xs:element ref="TOCSubsection"/>
          <xs:element ref="TOCDivision"/>
        </xs:choice>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="TOCSubsection">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="SubsectionTitle"/>
        <xs:element minOccurs="0" ref="ArticleRange"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="TOCDivision"/>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="TOCDivision">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="DivisionTitle"/>
        <xs:element minOccurs="0" ref="ArticleRange"/>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="TOCArticle">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="ArticleTitle"/>
        <xs:element ref="ArticleCaption"/>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="TOCSupplProvision">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="SupplProvisionLabel"/>
        <xs:element minOccurs="0" ref="ArticleRange"/>
        <xs:choice minOccurs="0" maxOccurs="unbounded">
          <xs:element ref="TOCArticle"/>
          <xs:element ref="TOCChapter"/>
        </xs:choice>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
  <xs:element name="TOCAppdxTableLabel">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="ArticleRange">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Preamble ============================================================== -->
  <xs:element name="Preamble">
    <xs:complexType>
      <xs:sequence>
        <xs:element maxOccurs="unbounded" ref="Paragraph"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
  <!--  MainProvision ========================================================= -->
  <xs:element name="MainProvision">
    <xs:complexType>
      <xs:choice>
        <xs:element maxOccurs="unbounded" ref="Part"/>
        <xs:element maxOccurs="unbounded" ref="Chapter"/>
        <xs:element maxOccurs="unbounded" ref="Section"/>
        <xs:element maxOccurs="unbounded" ref="Article"/>
        <xs:element maxOccurs="unbounded" ref="Paragraph"/>
      </xs:choice>
      <xs:attribute name="Extract" type="xs:boolean"/>
    </xs:complexType>
  </xs:element>
  <!--  Part ================================================================== -->
  <xs:element name="Part">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="PartTitle"/>
        <xs:choice>
          <xs:sequence>
            <xs:element maxOccurs="unbounded" ref="Article"/>
            <xs:element minOccurs="0" maxOccurs="unbounded" ref="Chapter"/>
          </xs:sequence>
          <xs:element maxOccurs="unbounded" ref="Chapter"/>
        </xs:choice>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
      <xs:attribute name="Hide" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="PartTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Chapter =============================================================== -->
  <xs:element name="Chapter">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="ChapterTitle"/>
        <xs:choice>
          <xs:sequence>
            <xs:element maxOccurs="unbounded" ref="Article"/>
            <xs:element minOccurs="0" maxOccurs="unbounded" ref="Section"/>
          </xs:sequence>
          <xs:element maxOccurs="unbounded" ref="Section"/>
        </xs:choice>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
      <xs:attribute name="Hide" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="ChapterTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Section =============================================================== -->
  <xs:element name="Section">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="SectionTitle"/>
        <xs:choice>
          <xs:sequence>
            <xs:element maxOccurs="unbounded" ref="Article"/>
            <xs:element minOccurs="0" maxOccurs="unbounded" ref="Subsection"/>
          </xs:sequence>
          <xs:element maxOccurs="unbounded" ref="Subsection"/>
          <xs:element maxOccurs="unbounded" ref="Division"/>
        </xs:choice>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
      <xs:attribute name="Hide" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="SectionTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Subsection ============================================================ -->
  <xs:element name="Subsection">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="SubsectionTitle"/>
        <xs:choice>
          <xs:sequence>
            <xs:element maxOccurs="unbounded" ref="Article"/>
            <xs:element minOccurs="0" maxOccurs="unbounded" ref="Division"/>
          </xs:sequence>
          <xs:element maxOccurs="unbounded" ref="Division"/>
        </xs:choice>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
      <xs:attribute name="Hide" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="SubsectionTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Division ============================================================== -->
  <xs:element name="Division">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="DivisionTitle"/>
        <xs:element maxOccurs="unbounded" ref="Article"/>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
      <xs:attribute name="Hide" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="DivisionTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Article =============================================================== -->
  <xs:element name="Article">
    <xs:complexType>
      <xs:sequence>
        <xs:choice>
          <xs:sequence>
            <xs:element ref="ArticleCaption"/>
            <xs:element ref="ArticleTitle"/>
          </xs:sequence>
          <xs:sequence>
            <xs:element ref="ArticleTitle"/>
            <xs:element minOccurs="0" ref="ArticleCaption"/>
          </xs:sequence>
        </xs:choice>
        <xs:element maxOccurs="unbounded" ref="Paragraph"/>
        <xs:element minOccurs="0" ref="SupplNote"/>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
      <xs:attribute name="Hide" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="ArticleTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="ArticleCaption">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
      <xs:attribute name="CommonCaption" type="xs:boolean"/>
    </xs:complexType>
  </xs:element>
  <!--  Paragraph ============================================================= -->
  <xs:element name="Paragraph">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="ParagraphCaption"/>
        <xs:element ref="ParagraphNum"/>
        <xs:element ref="ParagraphSentence"/>
        <xs:choice>
          <xs:element minOccurs="0" maxOccurs="unbounded" ref="AmendProvision"/>
          <xs:element minOccurs="0" maxOccurs="unbounded" ref="Class"/>
          <xs:sequence>
            <xs:choice maxOccurs="unbounded">
              <xs:element ref="TableStruct"/>
              <xs:element ref="FigStruct"/>
              <xs:element ref="StyleStruct"/>
            </xs:choice>
            <xs:element minOccurs="0" maxOccurs="unbounded" ref="Item"/>
          </xs:sequence>
          <xs:sequence>
            <xs:element maxOccurs="unbounded" ref="Item"/>
            <xs:choice minOccurs="0" maxOccurs="unbounded">
              <xs:element ref="TableStruct"/>
              <xs:element ref="FigStruct"/>
              <xs:element ref="StyleStruct"/>
              <xs:element ref="List"/>
            </xs:choice>
          </xs:sequence>
        </xs:choice>
      </xs:sequence>
      <xs:attribute name="Num" use="required" type="xs:positiveInteger"/>
      <xs:attribute name="OldStyle" type="xs:boolean" default="false"/>
      <xs:attribute name="OldNum" type="xs:boolean" default="false"/>
      <xs:attribute name="Hide" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="ParagraphCaption">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
      <xs:attribute name="CommonCaption" type="xs:boolean"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="ParagraphNum">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="ParagraphSentence">
    <xs:complexType>
      <xs:sequence>
        <xs:element maxOccurs="unbounded" ref="Sentence"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
  <!--  SupplNote ============================================================= -->
  <xs:element name="SupplNote">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  AmendProvision ======================================================== -->
  <xs:element name="AmendProvision">
    <xs:complexType>
      <xs:choice>
        <xs:sequence>
          <xs:element ref="AmendProvisionSentence"/>
          <xs:element minOccurs="0" maxOccurs="unbounded" ref="NewProvision"/>
        </xs:sequence>
        <xs:element maxOccurs="unbounded" ref="NewProvision"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="AmendProvisionSentence">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="Sentence"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
  <xs:element name="NewProvision">
    <xs:complexType>
      <xs:choice>
        <xs:choice>
          <xs:element ref="LawTitle"/>
          <xs:element ref="Preamble"/>
          <xs:element ref="TOC"/>
          <xs:element maxOccurs="unbounded" ref="Part"/>
          <xs:element maxOccurs="unbounded" ref="PartTitle"/>
          <xs:element maxOccurs="unbounded" ref="Chapter"/>
          <xs:element maxOccurs="unbounded" ref="ChapterTitle"/>
          <xs:element maxOccurs="unbounded" ref="Section"/>
          <xs:element maxOccurs="unbounded" ref="SectionTitle"/>
          <xs:element maxOccurs="unbounded" ref="Subsection"/>
          <xs:element maxOccurs="unbounded" ref="SubsectionTitle"/>
          <xs:element maxOccurs="unbounded" ref="Division"/>
          <xs:element maxOccurs="unbounded" ref="DivisionTitle"/>
          <xs:element maxOccurs="unbounded" ref="Article"/>
          <xs:element maxOccurs="unbounded" ref="SupplNote"/>
          <xs:element maxOccurs="unbounded" ref="Paragraph"/>
          <xs:element maxOccurs="unbounded" ref="Item"/>
          <xs:element maxOccurs="unbounded" ref="Subitem1"/>
          <xs:element maxOccurs="unbounded" ref="Subitem2"/>
          <xs:element maxOccurs="unbounded" ref="Subitem3"/>
          <xs:element maxOccurs="unbounded" ref="Subitem4"/>
          <xs:element maxOccurs="unbounded" ref="Subitem5"/>
          <xs:element maxOccurs="unbounded" ref="Subitem6"/>
          <xs:element maxOccurs="unbounded" ref="Subitem7"/>
          <xs:element maxOccurs="unbounded" ref="Subitem8"/>
          <xs:element maxOccurs="unbounded" ref="Subitem9"/>
          <xs:element maxOccurs="unbounded" ref="Subitem10"/>
          <xs:element maxOccurs="unbounded" ref="List"/>
          <xs:element maxOccurs="unbounded" ref="Sentence"/>
          <xs:element maxOccurs="unbounded" ref="AmendProvision"/>
          <xs:element maxOccurs="unbounded" ref="AppdxTable"/>
          <xs:element maxOccurs="unbounded" ref="AppdxNote"/>
          <xs:element maxOccurs="unbounded" ref="AppdxStyle"/>
          <xs:element maxOccurs="unbounded" ref="Appdx"/>
          <xs:element maxOccurs="unbounded" ref="AppdxFig"/>
          <xs:element maxOccurs="unbounded" ref="AppdxFormat"/>
          <xs:element maxOccurs="unbounded" ref="SupplProvisionAppdxStyle"/>
          <xs:element maxOccurs="unbounded" ref="SupplProvisionAppdxTable"/>
          <xs:element maxOccurs="unbounded" ref="SupplProvisionAppdx"/>
          <xs:element ref="TableStruct"/>
          <xs:element maxOccurs="unbounded" ref="TableRow"/>
          <xs:element maxOccurs="unbounded" ref="TableColumn"/>
          <xs:element ref="FigStruct"/>
          <xs:element ref="NoteStruct"/>
          <xs:element ref="StyleStruct"/>
          <xs:element ref="FormatStruct"/>
          <xs:element ref="Remarks"/>
        </xs:choice>
        <xs:element ref="LawBody"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Class ================================================================= -->
  <xs:element name="Class">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="ClassTitle"/>
        <xs:element ref="ClassSentence"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="Item"/>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="ClassTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="ClassSentence">
    <xs:complexType>
      <xs:choice>
        <xs:element maxOccurs="unbounded" ref="Sentence"/>
        <xs:element maxOccurs="unbounded" ref="Column"/>
        <xs:element ref="Table"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Item ================================================================== -->
  <xs:element name="Item">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="ItemTitle"/>
        <xs:element ref="ItemSentence"/>
        <xs:sequence>
          <xs:element minOccurs="0" maxOccurs="unbounded" ref="Subitem1"/>
          <xs:choice minOccurs="0" maxOccurs="unbounded">
            <xs:element ref="TableStruct"/>
            <xs:element ref="FigStruct"/>
            <xs:element ref="StyleStruct"/>
            <xs:element ref="List"/>
          </xs:choice>
        </xs:sequence>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
      <xs:attribute name="Hide" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="ItemTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="ItemSentence">
    <xs:complexType>
      <xs:choice>
        <xs:element maxOccurs="unbounded" ref="Sentence"/>
        <xs:element maxOccurs="unbounded" ref="Column"/>
        <xs:element ref="Table"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Subitem level1 ======================================================== -->
  <xs:element name="Subitem1">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="Subitem1Title"/>
        <xs:element ref="Subitem1Sentence"/>
        <xs:sequence>
          <xs:element minOccurs="0" maxOccurs="unbounded" ref="Subitem2"/>
          <xs:choice minOccurs="0" maxOccurs="unbounded">
            <xs:element ref="TableStruct"/>
            <xs:element ref="FigStruct"/>
            <xs:element ref="StyleStruct"/>
            <xs:element ref="List"/>
          </xs:choice>
        </xs:sequence>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
      <xs:attribute name="Hide" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="Subitem1Title">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="Subitem1Sentence">
    <xs:complexType>
      <xs:choice>
        <xs:element maxOccurs="unbounded" ref="Sentence"/>
        <xs:element maxOccurs="unbounded" ref="Column"/>
        <xs:element ref="Table"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Subitem level2 ======================================================== -->
  <xs:element name="Subitem2">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="Subitem2Title"/>
        <xs:element ref="Subitem2Sentence"/>
        <xs:sequence>
          <xs:element minOccurs="0" maxOccurs="unbounded" ref="Subitem3"/>
          <xs:choice minOccurs="0" maxOccurs="unbounded">
            <xs:element ref="TableStruct"/>
            <xs:element ref="FigStruct"/>
            <xs:element ref="StyleStruct"/>
            <xs:element ref="List"/>
          </xs:choice>
        </xs:sequence>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
      <xs:attribute name="Hide" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="Subitem2Title">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="Subitem2Sentence">
    <xs:complexType>
      <xs:choice>
        <xs:element maxOccurs="unbounded" ref="Sentence"/>
        <xs:element maxOccurs="unbounded" ref="Column"/>
        <xs:element ref="Table"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Subitem level3 ======================================================== -->
  <xs:element name="Subitem3">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="Subitem3Title"/>
        <xs:element ref="Subitem3Sentence"/>
        <xs:sequence>
          <xs:element minOccurs="0" maxOccurs="unbounded" ref="Subitem4"/>
          <xs:choice minOccurs="0" maxOccurs="unbounded">
            <xs:element ref="TableStruct"/>
            <xs:element ref="FigStruct"/>
            <xs:element ref="StyleStruct"/>
            <xs:element ref="List"/>
          </xs:choice>
        </xs:sequence>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
      <xs:attribute name="Hide" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="Subitem3Title">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="Subitem3Sentence">
    <xs:complexType>
      <xs:choice>
        <xs:element maxOccurs="unbounded" ref="Sentence"/>
        <xs:element maxOccurs="unbounded" ref="Column"/>
        <xs:element ref="Table"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Subitem level4 ======================================================== -->
  <xs:element name="Subitem4">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="Subitem4Title"/>
        <xs:element ref="Subitem4Sentence"/>
        <xs:sequence>
          <xs:element minOccurs="0" maxOccurs="unbounded" ref="Subitem5"/>
          <xs:choice minOccurs="0" maxOccurs="unbounded">
            <xs:element ref="TableStruct"/>
            <xs:element ref="FigStruct"/>
            <xs:element ref="StyleStruct"/>
            <xs:element ref="List"/>
          </xs:choice>
        </xs:sequence>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
      <xs:attribute name="Hide" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="Subitem4Title">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="Subitem4Sentence">
    <xs:complexType>
      <xs:choice>
        <xs:element maxOccurs="unbounded" ref="Sentence"/>
        <xs:element maxOccurs="unbounded" ref="Column"/>
        <xs:element ref="Table"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Subitem level5 ======================================================== -->
  <xs:element name="Subitem5">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="Subitem5Title"/>
        <xs:element ref="Subitem5Sentence"/>
        <xs:sequence>
          <xs:element minOccurs="0" maxOccurs="unbounded" ref="Subitem6"/>
          <xs:choice minOccurs="0" maxOccurs="unbounded">
            <xs:element ref="TableStruct"/>
            <xs:element ref="FigStruct"/>
            <xs:element ref="StyleStruct"/>
            <xs:element ref="List"/>
          </xs:choice>
        </xs:sequence>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
      <xs:attribute name="Hide" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="Subitem5Title">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="Subitem5Sentence">
    <xs:complexType>
      <xs:choice>
        <xs:element maxOccurs="unbounded" ref="Sentence"/>
        <xs:element maxOccurs="unbounded" ref="Column"/>
        <xs:element ref="Table"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Subitem level6 ======================================================== -->
  <xs:element name="Subitem6">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="Subitem6Title"/>
        <xs:element ref="Subitem6Sentence"/>
        <xs:sequence>
          <xs:element minOccurs="0" maxOccurs="unbounded" ref="Subitem7"/>
          <xs:choice minOccurs="0" maxOccurs="unbounded">
            <xs:element ref="TableStruct"/>
            <xs:element ref="FigStruct"/>
            <xs:element ref="StyleStruct"/>
            <xs:element ref="List"/>
          </xs:choice>
        </xs:sequence>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
      <xs:attribute name="Hide" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="Subitem6Title">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="Subitem6Sentence">
    <xs:complexType>
      <xs:choice>
        <xs:element maxOccurs="unbounded" ref="Sentence"/>
        <xs:element maxOccurs="unbounded" ref="Column"/>
        <xs:element ref="Table"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Subitem level7 ======================================================== -->
  <xs:element name="Subitem7">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="Subitem7Title"/>
        <xs:element ref="Subitem7Sentence"/>
        <xs:sequence>
          <xs:element minOccurs="0" maxOccurs="unbounded" ref="Subitem8"/>
          <xs:choice minOccurs="0" maxOccurs="unbounded">
            <xs:element ref="TableStruct"/>
            <xs:element ref="FigStruct"/>
            <xs:element ref="StyleStruct"/>
            <xs:element ref="List"/>
          </xs:choice>
        </xs:sequence>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
      <xs:attribute name="Hide" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="Subitem7Title">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="Subitem7Sentence">
    <xs:complexType>
      <xs:choice>
        <xs:element maxOccurs="unbounded" ref="Sentence"/>
        <xs:element maxOccurs="unbounded" ref="Column"/>
        <xs:element ref="Table"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Subitem level8 ======================================================== -->
  <xs:element name="Subitem8">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="Subitem8Title"/>
        <xs:element ref="Subitem8Sentence"/>
        <xs:sequence>
          <xs:element minOccurs="0" maxOccurs="unbounded" ref="Subitem9"/>
          <xs:choice minOccurs="0" maxOccurs="unbounded">
            <xs:element ref="TableStruct"/>
            <xs:element ref="FigStruct"/>
            <xs:element ref="StyleStruct"/>
            <xs:element ref="List"/>
          </xs:choice>
        </xs:sequence>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
      <xs:attribute name="Hide" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="Subitem8Title">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="Subitem8Sentence">
    <xs:complexType>
      <xs:choice>
        <xs:element maxOccurs="unbounded" ref="Sentence"/>
        <xs:element maxOccurs="unbounded" ref="Column"/>
        <xs:element ref="Table"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Subitem level9 ======================================================== -->
  <xs:element name="Subitem9">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="Subitem9Title"/>
        <xs:element ref="Subitem9Sentence"/>
        <xs:sequence>
          <xs:element minOccurs="0" maxOccurs="unbounded" ref="Subitem10"/>
          <xs:choice minOccurs="0" maxOccurs="unbounded">
            <xs:element ref="TableStruct"/>
            <xs:element ref="FigStruct"/>
            <xs:element ref="StyleStruct"/>
            <xs:element ref="List"/>
          </xs:choice>
        </xs:sequence>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
      <xs:attribute name="Hide" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="Subitem9Title">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="Subitem9Sentence">
    <xs:complexType>
      <xs:choice>
        <xs:element maxOccurs="unbounded" ref="Sentence"/>
        <xs:element maxOccurs="unbounded" ref="Column"/>
        <xs:element ref="Table"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Subitem level10 ======================================================== -->
  <xs:element name="Subitem10">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="Subitem10Title"/>
        <xs:element ref="Subitem10Sentence"/>
        <xs:choice minOccurs="0" maxOccurs="unbounded">
          <xs:element ref="TableStruct"/>
          <xs:element ref="FigStruct"/>
          <xs:element ref="StyleStruct"/>
          <xs:element ref="List"/>
        </xs:choice>
      </xs:sequence>
      <xs:attribute name="Num" use="required"/>
      <xs:attribute name="Delete" type="xs:boolean" default="false"/>
      <xs:attribute name="Hide" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="Subitem10Title">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="Subitem10Sentence">
    <xs:complexType>
      <xs:choice>
        <xs:element maxOccurs="unbounded" ref="Sentence"/>
        <xs:element maxOccurs="unbounded" ref="Column"/>
        <xs:element ref="Table"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Sentence ============================================================== -->
  <xs:element name="Sentence">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="QuoteStruct"/>
        <xs:element ref="ArithFormula"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
      <xs:attribute name="Num" type="xs:positiveInteger"/>
      <xs:attribute name="Function">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="main"/>
            <xs:enumeration value="proviso"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
      <xs:attribute name="Indent">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="Paragraph"/>
            <xs:enumeration value="Item"/>
            <xs:enumeration value="Subitem1"/>
            <xs:enumeration value="Subitem2"/>
            <xs:enumeration value="Subitem3"/>
            <xs:enumeration value="Subitem4"/>
            <xs:enumeration value="Subitem5"/>
            <xs:enumeration value="Subitem6"/>
            <xs:enumeration value="Subitem7"/>
            <xs:enumeration value="Subitem8"/>
            <xs:enumeration value="Subitem9"/>
            <xs:enumeration value="Subitem10"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
      <xs:attribute name="WritingMode" default="vertical">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="vertical"/>
            <xs:enumeration value="horizontal"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
    </xs:complexType>
  </xs:element>
  <!--  Column ================================================================ -->
  <xs:element name="Column">
    <xs:complexType>
      <xs:sequence>
        <xs:element maxOccurs="unbounded" ref="Sentence"/>
      </xs:sequence>
      <xs:attribute name="Num" type="xs:positiveInteger"/>
      <xs:attribute name="LineBreak" type="xs:boolean" default="false"/>
      <xs:attribute name="Align">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="left"/>
            <xs:enumeration value="center"/>
            <xs:enumeration value="right"/>
            <xs:enumeration value="justify"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
    </xs:complexType>
  </xs:element>
  <!--  SupplProvision ======================================================== -->
  <xs:element name="SupplProvision">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="SupplProvisionLabel"/>
        <xs:choice maxOccurs="unbounded">
          <xs:element ref="Chapter"/>
          <xs:element ref="Article"/>
          <xs:element ref="Paragraph"/>
        </xs:choice>
        <xs:choice minOccurs="0" maxOccurs="unbounded">
          <xs:element ref="SupplProvisionAppdxTable"/>
          <xs:element ref="SupplProvisionAppdxStyle"/>
          <xs:element ref="SupplProvisionAppdx"/>
        </xs:choice>
      </xs:sequence>
      <xs:attribute name="Type">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="New"/>
            <xs:enumeration value="Amend"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
      <xs:attribute name="AmendLawNum"/>
      <xs:attribute name="Extract" type="xs:boolean"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="SupplProvisionLabel">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="SupplProvisionAppdxTable">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="SupplProvisionAppdxTableTitle"/>
        <xs:element minOccurs="0" ref="RelatedArticleNum"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="TableStruct"/>
      </xs:sequence>
      <xs:attribute name="Num" type="xs:positiveInteger"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="SupplProvisionAppdxTableTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
      <xs:attribute name="WritingMode" default="vertical">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="vertical"/>
            <xs:enumeration value="horizontal"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
    </xs:complexType>
  </xs:element>
  <xs:element name="SupplProvisionAppdxStyle">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="SupplProvisionAppdxStyleTitle"/>
        <xs:element minOccurs="0" ref="RelatedArticleNum"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="StyleStruct"/>
      </xs:sequence>
      <xs:attribute name="Num" type="xs:positiveInteger"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="SupplProvisionAppdxStyleTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
      <xs:attribute name="WritingMode" default="vertical">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="vertical"/>
            <xs:enumeration value="horizontal"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
    </xs:complexType>
  </xs:element>
  <xs:element name="SupplProvisionAppdx">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="ArithFormulaNum"/>
        <xs:element minOccurs="0" ref="RelatedArticleNum"/>
        <xs:element maxOccurs="unbounded" ref="ArithFormula"/>
      </xs:sequence>
      <xs:attribute name="Num" type="xs:positiveInteger"/>
    </xs:complexType>
  </xs:element>
  <!--  AppdxTable ============================================================ -->
  <xs:element name="AppdxTable">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="AppdxTableTitle"/>
        <xs:element minOccurs="0" ref="RelatedArticleNum"/>
        <xs:choice minOccurs="0" maxOccurs="unbounded">
          <xs:element maxOccurs="unbounded" ref="TableStruct"/>
          <xs:element maxOccurs="unbounded" ref="Item"/>
        </xs:choice>
        <xs:element minOccurs="0" ref="Remarks"/>
      </xs:sequence>
      <xs:attribute name="Num" type="xs:positiveInteger"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="AppdxTableTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
      <xs:attribute name="WritingMode" default="vertical">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="vertical"/>
            <xs:enumeration value="horizontal"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
    </xs:complexType>
  </xs:element>
  <!--  AppdxNote ============================================================= -->
  <xs:element name="AppdxNote">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="AppdxNoteTitle"/>
        <xs:element minOccurs="0" ref="RelatedArticleNum"/>
        <xs:choice minOccurs="0" maxOccurs="unbounded">
          <xs:element ref="NoteStruct"/>
          <xs:element ref="FigStruct"/>
          <xs:element ref="TableStruct"/>
        </xs:choice>
        <xs:element minOccurs="0" ref="Remarks"/>
      </xs:sequence>
      <xs:attribute name="Num" type="xs:positiveInteger"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="AppdxNoteTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
      <xs:attribute name="WritingMode" default="vertical">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="vertical"/>
            <xs:enumeration value="horizontal"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
    </xs:complexType>
  </xs:element>
  <!--  AppdxStyle ============================================================ -->
  <xs:element name="AppdxStyle">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="AppdxStyleTitle"/>
        <xs:element minOccurs="0" ref="RelatedArticleNum"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="StyleStruct"/>
        <xs:element minOccurs="0" ref="Remarks"/>
      </xs:sequence>
      <xs:attribute name="Num" type="xs:positiveInteger"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="AppdxStyleTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
      <xs:attribute name="WritingMode" default="vertical">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="vertical"/>
            <xs:enumeration value="horizontal"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
    </xs:complexType>
  </xs:element>
  <!--  AppdxFormat =========================================================== -->
  <xs:element name="AppdxFormat">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="AppdxFormatTitle"/>
        <xs:element minOccurs="0" ref="RelatedArticleNum"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="FormatStruct"/>
        <xs:element minOccurs="0" ref="Remarks"/>
      </xs:sequence>
      <xs:attribute name="Num" type="xs:positiveInteger"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="AppdxFormatTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
      <xs:attribute name="WritingMode" default="vertical">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="vertical"/>
            <xs:enumeration value="horizontal"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
    </xs:complexType>
  </xs:element>
  <!--  Appdx ================================================================= -->
  <xs:element name="Appdx">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="ArithFormulaNum"/>
        <xs:element minOccurs="0" ref="RelatedArticleNum"/>
        <xs:element maxOccurs="unbounded" ref="ArithFormula"/>
        <xs:element minOccurs="0" ref="Remarks"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
  <xs:element name="ArithFormulaNum">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="ArithFormula">
    <xs:complexType>
      <xs:complexContent>
        <xs:extension base="any">
          <xs:attribute name="Num" type="xs:positiveInteger"/>
        </xs:extension>
      </xs:complexContent>
    </xs:complexType>
  </xs:element>
  <!--  AppdxFig ============================================================== -->
  <xs:element name="AppdxFig">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="AppdxFigTitle"/>
        <xs:element minOccurs="0" ref="RelatedArticleNum"/>
        <xs:choice maxOccurs="unbounded">
          <xs:element ref="FigStruct"/>
          <xs:element ref="TableStruct"/>
        </xs:choice>
      </xs:sequence>
      <xs:attribute name="Num" type="xs:positiveInteger"/>
    </xs:complexType>
  </xs:element>
  <xs:element name="AppdxFigTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
      <xs:attribute name="WritingMode" default="vertical">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="vertical"/>
            <xs:enumeration value="horizontal"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
    </xs:complexType>
  </xs:element>
  <!--  Table ================================================================= -->
  <xs:element name="TableStruct">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="TableStructTitle"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="Remarks"/>
        <xs:element ref="Table"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="Remarks"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
  <xs:element name="TableStructTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
      <xs:attribute name="WritingMode" default="vertical">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="vertical"/>
            <xs:enumeration value="horizontal"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
    </xs:complexType>
  </xs:element>
  <xs:element name="Table">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="TableHeaderRow"/>
        <xs:element maxOccurs="unbounded" ref="TableRow"/>
      </xs:sequence>
      <xs:attribute name="WritingMode" default="vertical">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="vertical"/>
            <xs:enumeration value="horizontal"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
    </xs:complexType>
  </xs:element>
  <xs:element name="TableRow">
    <xs:complexType>
      <xs:sequence>
        <xs:element maxOccurs="unbounded" ref="TableColumn"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
  <xs:element name="TableHeaderRow">
    <xs:complexType>
      <xs:sequence>
        <xs:element maxOccurs="unbounded" ref="TableHeaderColumn"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
  <xs:element name="TableHeaderColumn">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="TableColumn">
    <xs:complexType>
      <xs:choice>
        <xs:element maxOccurs="unbounded" ref="Part"/>
        <xs:element maxOccurs="unbounded" ref="Chapter"/>
        <xs:element maxOccurs="unbounded" ref="Section"/>
        <xs:element maxOccurs="unbounded" ref="Subsection"/>
        <xs:element maxOccurs="unbounded" ref="Division"/>
        <xs:element maxOccurs="unbounded" ref="Article"/>
        <xs:element maxOccurs="unbounded" ref="Paragraph"/>
        <xs:element maxOccurs="unbounded" ref="Item"/>
        <xs:element maxOccurs="unbounded" ref="Subitem1"/>
        <xs:element maxOccurs="unbounded" ref="Subitem2"/>
        <xs:element maxOccurs="unbounded" ref="Subitem3"/>
        <xs:element maxOccurs="unbounded" ref="Subitem4"/>
        <xs:element maxOccurs="unbounded" ref="Subitem5"/>
        <xs:element maxOccurs="unbounded" ref="Subitem6"/>
        <xs:element maxOccurs="unbounded" ref="Subitem7"/>
        <xs:element maxOccurs="unbounded" ref="Subitem8"/>
        <xs:element maxOccurs="unbounded" ref="Subitem9"/>
        <xs:element maxOccurs="unbounded" ref="Subitem10"/>
        <xs:element maxOccurs="unbounded" ref="FigStruct"/>
        <xs:element ref="Remarks"/>
        <xs:element maxOccurs="unbounded" ref="Sentence"/>
        <xs:element maxOccurs="unbounded" ref="Column"/>
      </xs:choice>
      <xs:attribute name="BorderTop" default="solid">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="solid"/>
            <xs:enumeration value="none"/>
            <xs:enumeration value="dotted"/>
            <xs:enumeration value="double"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
      <xs:attribute name="BorderBottom" default="solid">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="solid"/>
            <xs:enumeration value="none"/>
            <xs:enumeration value="dotted"/>
            <xs:enumeration value="double"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
      <xs:attribute name="BorderLeft" default="solid">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="solid"/>
            <xs:enumeration value="none"/>
            <xs:enumeration value="dotted"/>
            <xs:enumeration value="double"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
      <xs:attribute name="BorderRight" default="solid">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="solid"/>
            <xs:enumeration value="none"/>
            <xs:enumeration value="dotted"/>
            <xs:enumeration value="double"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
      <xs:attribute name="rowspan"/>
      <xs:attribute name="colspan"/>
      <xs:attribute name="Align">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="left"/>
            <xs:enumeration value="center"/>
            <xs:enumeration value="right"/>
            <xs:enumeration value="justify"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
      <xs:attribute name="Valign">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="top"/>
            <xs:enumeration value="middle"/>
            <xs:enumeration value="bottom"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
    </xs:complexType>
  </xs:element>
  <!--  Fig =================================================================== -->
  <xs:element name="FigStruct">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="FigStructTitle"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="Remarks"/>
        <xs:element ref="Fig"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="Remarks"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
  <xs:element name="FigStructTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="Fig">
    <xs:complexType>
      <xs:attribute name="src" use="required"/>
    </xs:complexType>
  </xs:element>
  <!--  Note ================================================================== -->
  <xs:element name="NoteStruct">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="NoteStructTitle"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="Remarks"/>
        <xs:element ref="Note"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="Remarks"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
  <xs:element name="NoteStructTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="Note" type="any"/>
  <!--  Style ================================================================= -->
  <xs:element name="StyleStruct">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="StyleStructTitle"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="Remarks"/>
        <xs:element ref="Style"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="Remarks"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
  <xs:element name="StyleStructTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="Style" type="any"/>
  <!--  Format ================================================================ -->
  <xs:element name="FormatStruct">
    <xs:complexType>
      <xs:sequence>
        <xs:element minOccurs="0" ref="FormatStructTitle"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="Remarks"/>
        <xs:element ref="Format"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="Remarks"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
  <xs:element name="FormatStructTitle">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <xs:element name="Format" type="any"/>
  <!--  Common ================================================================ -->
  <xs:element name="RelatedArticleNum">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Remarks =============================================================== -->
  <xs:element name="Remarks">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="RemarksLabel"/>
        <xs:choice>
          <xs:element maxOccurs="unbounded" ref="Item"/>
          <xs:element maxOccurs="unbounded" ref="Sentence"/>
        </xs:choice>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
  <xs:element name="RemarksLabel">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="Line"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
      <xs:attribute name="LineBreak" type="xs:boolean" default="false"/>
    </xs:complexType>
  </xs:element>
  <!--  List ================================================================== -->
  <xs:element name="List">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="ListSentence"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="Sublist1"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
  <xs:element name="ListSentence">
    <xs:complexType>
      <xs:choice>
        <xs:element maxOccurs="unbounded" ref="Sentence"/>
        <xs:element maxOccurs="unbounded" ref="Column"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Sublist level1========================================================= -->
  <xs:element name="Sublist1">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="Sublist1Sentence"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="Sublist2"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
  <xs:element name="Sublist1Sentence">
    <xs:complexType>
      <xs:choice>
        <xs:element maxOccurs="unbounded" ref="Sentence"/>
        <xs:element maxOccurs="unbounded" ref="Column"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Sublist level2========================================================= -->
  <xs:element name="Sublist2">
    <xs:complexType>
      <xs:sequence>
        <xs:element ref="Sublist2Sentence"/>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="Sublist3"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
  <xs:element name="Sublist2Sentence">
    <xs:complexType>
      <xs:choice>
        <xs:element maxOccurs="unbounded" ref="Sentence"/>
        <xs:element maxOccurs="unbounded" ref="Column"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  Sublist level3========================================================= -->
  <xs:element name="Sublist3" type="Sublist3Sentence"/>
  <xs:complexType name="Sublist3Sentence">
    <xs:sequence>
      <xs:element ref="Sublist3Sentence"/>
    </xs:sequence>
  </xs:complexType>
  <xs:element name="Sublist3Sentence">
    <xs:complexType>
      <xs:choice>
        <xs:element maxOccurs="unbounded" ref="Sentence"/>
        <xs:element maxOccurs="unbounded" ref="Column"/>
      </xs:choice>
    </xs:complexType>
  </xs:element>
  <!--  QuoteStruct =========================================================== -->
  <xs:element name="QuoteStruct" type="any"/>
  <!--  Ruby ================================================================== -->
  <xs:element name="Ruby">
    <xs:complexType mixed="true">
      <xs:sequence>
        <xs:element minOccurs="0" maxOccurs="unbounded" ref="Rt"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
  <xs:element name="Rt" type="xs:string"/>
  <!--  Line ================================================================== -->
  <xs:element name="Line">
    <xs:complexType mixed="true">
      <xs:choice minOccurs="0" maxOccurs="unbounded">
        <xs:element ref="QuoteStruct"/>
        <xs:element ref="ArithFormula"/>
        <xs:element ref="Ruby"/>
        <xs:element ref="Sup"/>
        <xs:element ref="Sub"/>
      </xs:choice>
      <xs:attribute name="Style" default="solid">
        <xs:simpleType>
          <xs:restriction base="xs:token">
            <xs:enumeration value="dotted"/>
            <xs:enumeration value="double"/>
            <xs:enumeration value="none"/>
            <xs:enumeration value="solid"/>
          </xs:restriction>
        </xs:simpleType>
      </xs:attribute>
    </xs:complexType>
  </xs:element>
  <!--  Sup =================================================================== -->
  <xs:element name="Sup" type="xs:string"/>
  <!--  Sub =================================================================== -->
  <xs:element name="Sub" type="xs:string"/>
  <xs:complexType name="any" mixed="true">
    <xs:sequence>
      <xs:any minOccurs="0" maxOccurs="unbounded" processContents="strict"/>
    </xs:sequence>
  </xs:complexType>
</xs:schema>
