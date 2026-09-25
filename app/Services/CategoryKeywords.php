<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Eingebaute Schlüsselwörter → Name der Standard-Kategorie.
 * Greift nur, wenn weder eigene Regeln noch gelernte Zuordnungen passen.
 */
final class CategoryKeywords
{
    /** Einkaufsposten (normalisierter Produktname) */
    public const ITEMS = [
        'Obst & Gemüse' => ['apfel', 'äpfel', 'banane', 'birne', 'tomate', 'gurke', 'salat', 'kartoffel', 'zwiebel', 'paprika', 'möhre',
            'karotte', 'zitrone', 'limette', 'orange', 'mandarine', 'traube', 'beere', 'erdbeer', 'himbeer', 'heidelbeer', 'obst', 'gemüse',
            'zucchini', 'aubergine', 'brokkoli', 'blumenkohl', 'kohl', 'lauch', 'porree', 'knoblauch', 'ingwer', 'pilz', 'champignon',
            'avocado', 'mango', 'ananas', 'melone', 'kiwi', 'pfirsich', 'nektarine', 'pflaume', 'kirsche', 'spinat', 'rucola', 'radieschen',
            'sellerie', 'spargel', 'mais', 'fenchel', 'kräuter', 'petersilie', 'schnittlauch', 'basilikum', 'kürbis', 'rote bete'],
        'Brot & Backwaren' => ['brot', 'brötchen', 'broetchen', 'toast', 'croissant', 'baguette', 'semmel', 'laugen', 'brezel', 'breze',
            'kuchen', 'berliner', 'krapfen', 'ciabatta', 'bagel', 'wrap', 'tortilla', 'knäcke', 'zwieback', 'schnecke'],
        'Milchprodukte & Eier' => ['milch', 'joghurt', 'jogurt', 'käse', 'kaese', 'butter', 'sahne', 'quark', 'eier', 'skyr', 'kefir',
            'buttermilch', 'schmand', 'creme fraiche', 'mozzarella', 'gouda', 'feta', 'parmesan', 'frischkäse', 'margarine', 'pudding', 'ei'],
        'Fleisch & Fisch' => ['hähnchen', 'haehnchen', 'hühnchen', 'huhn', 'pute', 'schnitzel', 'hack', 'wurst', 'würstchen', 'salami',
            'schinken', 'fisch', 'lachs', 'thunfisch', 'fleisch', 'steak', 'bacon', 'speck', 'rind', 'schwein', 'leberkäse', 'bratwurst',
            'wiener', 'garnele', 'shrimps', 'forelle', 'hering', 'kassler', 'aufschnitt', 'filet', 'gulasch', 'frikadelle'],
        'Tiefkühl' => ['tk', 'tiefkühl', 'tiefgefroren', 'iglo', 'frosta', 'pommes', 'fischstäbchen', 'rahmspinat'],
        'Vorrat & Konserven' => ['nudel', 'spaghetti', 'penne', 'fusilli', 'lasagne', 'reis', 'mehl', 'zucker', 'öl', 'oel', 'essig',
            'konserve', 'dose', 'tomatenmark', 'passata', 'müsli', 'muesli', 'haferflocken', 'cornflakes', 'kaffee', 'tee', 'gewürz',
            'salz', 'pfeffer', 'rapsöl', 'olivenöl', 'sonnenblumenöl', 'speiseöl', 'ketchup', 'senf', 'mayo', 'mayonnaise', 'brühe', 'soße', 'sauce', 'honig', 'marmelade', 'konfitüre',
            'nutella', 'aufstrich', 'linsen', 'bohnen', 'kichererbsen', 'erbsen', 'hefe', 'backpulver', 'vanille', 'nüsse', 'mandeln',
            'rosinen', 'couscous', 'bulgur', 'quinoa', 'grieß', 'oliven', 'pesto', 'paniermehl', 'kakao', 'cappuccino', 'espresso'],
        'Süßes & Snacks' => ['schoko', 'schokolade', 'chips', 'keks', 'kekse', 'gummi', 'bonbon', 'eis', 'speiseeis', 'praline', 'riegel',
            'waffel', 'lakritz', 'flips', 'salzstangen', 'popcorn', 'haribo', 'milka', 'ritter sport', 'kinder', 'duplo', 'snickers',
            'twix', 'mars', 'nuss', 'cracker', 'studentenfutter'],
        'Getränke' => ['wasser', 'mineralwasser', 'saft', 'cola', 'limo', 'limonade', 'sprudel', 'fanta', 'sprite', 'mate', 'schorle',
            'eistee', 'energy', 'red bull', 'smoothie', 'nektar', 'apfelschorle', 'tonic'],
        'Alkohol' => ['bier', 'wein', 'sekt', 'prosecco', 'vodka', 'wodka', 'whisky', 'rum', 'gin', 'pils', 'weizen', 'radler',
            'likör', 'schnaps', 'aperol', 'champagner', 'riesling', 'merlot', 'grauburgunder', 'rotwein', 'weißwein'],
        'Fertiggerichte' => ['pizza', 'fertig', 'lasagne', 'ravioli', 'suppe', 'eintopf', 'instant', 'mikrowelle', 'maultaschen', 'gnocchi'],
        'Pfand' => ['pfand', 'leergut', 'einweg', 'mehrweg'],
        'Körperpflege' => ['shampoo', 'duschgel', 'dusch', 'zahnpasta', 'zahncreme', 'zahnbürste', 'deo', 'seife', 'creme', 'rasier',
            'tampon', 'binden', 'bodylotion', 'lotion', 'haargel', 'haarspray', 'spülung', 'wattepads', 'wattestäbchen', 'sonnencreme',
            'nivea', 'balea', 'make up', 'mascara', 'lippen', 'nagellack', 'mundspülung', 'zahnseide', 'rasierer'],
        'Reinigung' => ['spülmittel', 'waschmittel', 'reiniger', 'müllbeutel', 'schwamm', 'weichspüler', 'spültabs', 'geschirr', 'entkalker',
            'wc ', 'putz', 'lappen', 'glasreiniger', 'fleckentferner', 'domestos', 'persil', 'ariel', 'somat', 'finish', 'frosch', 'mülltüten'],
        'Haushaltswaren' => ['toilettenpapier', 'klopapier', 'küchenrolle', 'küchentücher', 'taschentücher', 'alufolie', 'frischhaltefolie',
            'backpapier', 'batterie', 'kerze', 'servietten', 'gefrierbeutel', 'glühbirne', 'leuchtmittel', 'feuerzeug', 'streichhölzer'],
        'Baby & Kind' => ['windel', 'feuchttücher', 'babynahrung', 'hipp', 'alete', 'pampers', 'milupa', 'babybrei', 'schnuller'],
        'Tierbedarf' => ['katzen', 'hunde', 'tierfutter', 'katzenstreu', 'whiskas', 'felix', 'pedigree', 'sheba', 'leckerli'],
        'Apotheke' => ['ibuprofen', 'paracetamol', 'aspirin', 'pflaster', 'tabletten', 'nasenspray', 'hustensaft', 'vitamin'],
        'Bücher' => ['buch', 'zeitschrift', 'zeitung', 'magazin'],
        'Kleidung' => ['socken', 'shirt', 't-shirt', 'hose', 'jacke', 'pullover', 'unterwäsche', 'strumpf', 'schuhe'],
    ];

    /** Buchungen (Empfänger + Verwendungszweck) */
    public const TRANSACTIONS = [
        'Lebensmittel' => ['rewe', 'edeka', 'aldi', 'lidl', 'netto', 'penny', 'kaufland', 'norma', 'globus', 'tegut', 'marktkauf',
            'real ', 'hit ', 'famila', 'combi', 'nahkauf', 'bäckerei', 'baeckerei', 'metzgerei', 'denns', 'alnatura', 'bio company'],
        'Drogerie & Haushalt' => ['dm-drogerie', 'dm drogerie', 'rossmann', 'müller drogerie', 'budni'],
        'Tanken / Laden' => ['aral', 'shell', 'esso', 'jet tank', 'totalenergies', 'total ', 'tankstelle', 'agip', 'avia', 'star tank',
            'omv', 'hem ', 'ionity', 'enbw mobility', 'ladestation'],
        'Streaming & Abos' => ['netflix', 'spotify', 'disney plus', 'disney+', 'dazn', 'sky deutschland', 'apple.com/bill', 'youtube premium',
            'audible', 'amazon prime', 'rtl+', 'joyn'],
        'Miete / Rate' => ['miete', 'kaltmiete', 'warmmiete'],
        'Nebenkosten' => ['nebenkosten', 'hausgeld', 'betriebskosten'],
        'Gehalt' => ['lohn', 'gehalt', 'bezüge', 'bezuege', 'entgeltabrechnung'],
        'Kindergeld' => ['kindergeld', 'familienkasse'],
        'Rundfunkbeitrag' => ['rundfunk', 'beitragsservice', 'ard zdf'],
        'Apotheke' => ['apotheke'],
        'Internet & Telefon' => ['telekom', 'vodafone', 'o2 ', 'telefonica', '1&1', 'congstar', 'freenet', 'unitymedia', 'pyur', 'netcologne', 'm-net'],
        'Strom' => ['stadtwerke', 'e.on', 'eon energie', 'vattenfall', 'enbw', 'strom', 'lichtblick', 'yello', 'eprimo', 'tibber'],
        'Heizung / Gas' => ['gasversorgung', 'erdgas', 'heizöl', 'fernwärme'],
        'ÖPNV' => ['db vertrieb', 'deutsche bahn', 'bvg', 'mvg', 'hvv', 'kvb', 'rmv', 'vrr', 'deutschlandticket', 'flixbus'],
        'Haftpflicht' => ['haftpflicht'],
        'Versicherungen' => ['versicherung', 'allianz', 'huk-coburg', 'huk coburg', 'ergo ', 'axa ', 'devk', 'r+v', 'generali', 'signal iduna', 'debeka'],
        'KFZ-Steuer' => ['kraftfahrzeugsteuer', 'kfz-steuer', 'hauptzollamt'],
        'Zinsen' => ['sollzinsen', 'dispozinsen', 'überziehungszinsen'],
        'Gebühren' => ['kontoführung', 'kontofuehrung', 'entgelt', 'gebühr', 'gebuehr', 'kartenpreis'],
        'Restaurant & Café' => ['restaurant', 'mcdonald', 'burger king', 'lieferando', 'wolt', 'cafe ', 'café', 'pizzeria', 'kfc', 'subway',
            'starbucks', 'vapiano', 'imbiss', 'döner', 'doener'],
        'Kleidung' => ['h&m', 'zalando', 'c&a', 'primark', 'deichmann', 'about you', 'tk maxx', 'kik '],
        'Elektronik' => ['mediamarkt', 'media markt', 'saturn', 'cyberport', 'notebooksbilliger', 'alternate'],
        'Einrichtung' => ['ikea', 'höffner', 'xxxlutz', 'poco', 'roller', 'jysk', 'porta'],
        'Reparaturen' => ['obi ', 'bauhaus', 'hornbach', 'toom', 'hagebau'],
        'Erstattungen' => ['erstattung', 'rückerstattung', 'gutschrift'],
        'Zinserträge' => ['habenzinsen', 'zinsgutschrift'],
        'Taschengeld' => ['taschengeld'],
        'Arzt' => ['zahnarzt', 'arztpraxis', 'praxis dr', 'dr. med', 'klinik'],
        'Sport' => ['fitness', 'mcfit', 'urban sports', 'sportverein', 'turnverein', 'decathlon'],
        'Urlaub' => ['booking.com', 'airbnb', 'hotel', 'lufthansa', 'ryanair', 'eurowings', 'tui ', 'expedia', 'check24 reise'],
        'Kreditrate' => ['darlehen', 'kreditrate', 'tilgung', 'annuität'],
    ];
}
