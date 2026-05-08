// src/content.config.ts
import { defineCollection, z } from 'astro:content';
import { glob } from 'astro/loaders';

const faq = defineCollection({
  loader: glob({
    pattern: '**/*.mdx',
    base: './src/content/faq',
  }),
  schema: z.object({
    question: z.string(),
    // résumé court pour le JSON-LD (texte brut)
    answer: z.string(),
    order: z.number().optional(),
  }),
});

export const collections = { faq };