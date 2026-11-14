/**
 * Photos from Unsplash, free to use under the Unsplash License (https://unsplash.com/license).
 * Served from Unsplash's image CDN, as Unsplash asks, and credited in the page footer.
 */
export interface Photo { id: string; alt: string; by: string; page: string }

export const photos = {
  boutique: { id: 'photo-1751276651319-d311a9d0b8af', alt: 'A woman browsing clothes on a rack in a shop', by: 'Michael Umoh', page: 'https://unsplash.com/photos/woman-browses-clothing-on-a-rack-in-a-shop-s8KvpUV65sY' },
  tailor: { id: 'photo-1631628559428-f79ca90b9ef8', alt: 'A tailor holding a pair of scissors in his workshop', by: 'Oyemike Princewill', page: 'https://unsplash.com/photos/a-man-holding-a-pair-of-scissors-in-his-hand-S6j3Cf2dejI' },
  streetFood: { id: 'photo-1722072391426-964abfef1924', alt: 'A woman grilling plantain (bole) at a busy street food stall in Ibadan', by: 'Tunde Buremo', page: 'https://unsplash.com/photos/a-woman-is-selling-bananas-on-the-street-Tb9D4Ix9oYo' },
  tomatoes: { id: 'photo-1575303093127-18b3c4ef8c41', alt: 'A market trader holding fresh tomatoes at her stall', by: 'Omotayo Tajudeen', page: 'https://unsplash.com/photos/woman-holding-tomatoes-near-containers-of-tomatoes-zlZnNIS7eDk' },
  shop: { id: 'photo-1761370571806-886404629697', alt: 'A shop owner standing in his small shop', by: 'Muhammad-Taha Ibrahim', page: 'https://unsplash.com/photos/man-in-blue-shirt-standing-in-a-small-shop-iLv6fkrr6Jk' },
  market: { id: 'photo-1785094165709-54fef69c2c4d', alt: 'Aerial view of a busy Lagos street market with colourful umbrellas', by: 'Fahd Aminu', page: 'https://unsplash.com/photos/aerial-view-of-a-bustling-street-market-with-colorful-umbrellas-IWMQ5P9HxYM' },
  city: { id: 'photo-1785094165599-7fe887d264d4', alt: 'Lagos skyline with busy streets and the lagoon', by: 'Fahd Aminu', page: 'https://unsplash.com/photos/cityscape-with-tall-buildings-crowded-streets-and-a-river-i4No07Qvhl4' },
} satisfies Record<string, Photo>

export function src(p: Photo, w: number, h?: number) {
  return `https://images.unsplash.com/${p.id}?auto=format&fit=crop&w=${w}${h ? `&h=${h}` : ''}&q=70`
}

export function srcSet(p: Photo, w: number, h?: number) {
  const r = h ? h / w : undefined
  return [1, 2].map((x) => `${src(p, w * x, r ? Math.round(w * x * r) : undefined)} ${x}x`).join(', ')
}
