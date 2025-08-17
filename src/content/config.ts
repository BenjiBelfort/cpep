import { defineCollection, z } from 'astro:content';

const faq = defineCollection({
  type: 'content',
  schema: z.object({
    question: z.string(),
    // résumé court pour le JSON-LD (texte brut)
    answer: z.string(),
    order: z.number().optional()
  }),
});

export const collections = { faq };
