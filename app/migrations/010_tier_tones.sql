-- Tier badge tones as on Figma CU5 (the first seed had other colours) and in the case the tier editor uses.
UPDATE tiers SET tone = 'Neutral' WHERE name = 'Bronz' AND lower(tone) = 'attention';
UPDATE tiers SET tone = 'Accent' WHERE name = 'Gümüş' AND lower(tone) = 'neutral';
UPDATE tiers SET tone = 'Solid' WHERE name = 'Altın' AND lower(tone) = 'accent';
UPDATE tiers SET tone = upper(substr(tone, 1, 1)) || lower(substr(tone, 2)) WHERE tone <> upper(substr(tone, 1, 1)) || lower(substr(tone, 2));
