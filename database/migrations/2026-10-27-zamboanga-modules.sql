-- Run once against an existing allinoneweb database.
-- Consolidates old categories while preserving content by moving rows to the new category IDs.
START TRANSACTION;

-- Rename the existing creative arts category.
UPDATE categories SET name='Creative Arts and Photography', slug='creative-arts-photography',
 description='Photography, visual art, design and creative work'
WHERE slug='creative-arts';

-- Rename e-commerce to Marketplace first so it becomes the target for old property listings.
UPDATE categories SET name='Marketplace', slug='marketplace',
 description='Products, makers, services and listings'
WHERE slug='e-commerce';

-- Merge property listings into Marketplace without losing their existing content.
UPDATE content SET category_id=(SELECT id FROM categories WHERE slug='marketplace' LIMIT 1)
WHERE category_id=(SELECT id FROM categories WHERE slug='property-listing' LIMIT 1);
DELETE FROM categories WHERE slug='property-listing';

-- Merge old blog posts into News Connect.
UPDATE categories SET name='News Connect', slug='news-connect',
 description='Community updates, civic stories and current affairs'
WHERE slug='news';
UPDATE content SET category_id=(SELECT id FROM categories WHERE slug='news-connect' LIMIT 1)
WHERE category_id=(SELECT id FROM categories WHERE slug='blog' LIMIT 1);
DELETE FROM categories WHERE slug='blog';

-- Rename Entertainment and enable the five desired modules.
UPDATE categories SET name='Entertainment & Politics', slug='entertainment-politics',
 description='Entertainment, public figures, political discussion and analysis'
WHERE slug='entertainment';
UPDATE categories SET name='History & Culture', slug='history-culture',
 description='Zamboanga heritage, Chavacano identity and living traditions',
 is_active=1
WHERE slug='history-culture';

-- Add History & Culture if the previous database did not have this category.
INSERT INTO categories(name,slug,description,is_active)
SELECT 'History & Culture','history-culture','Zamboanga heritage, Chavacano identity and living traditions',1
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE slug='history-culture');

UPDATE categories SET is_active=1
WHERE slug IN ('creative-arts-photography','news-connect','marketplace','entertainment-politics','history-culture');

UPDATE categories SET is_active=0
WHERE slug NOT IN ('creative-arts-photography','news-connect','marketplace','entertainment-politics','history-culture');

COMMIT;
