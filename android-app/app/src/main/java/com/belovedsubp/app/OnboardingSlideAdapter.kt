package com.belovedsubp.app

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.ImageView
import android.widget.TextView
import androidx.recyclerview.widget.RecyclerView

class OnboardingSlideAdapter(
    private val items: List<OnboardingSlide>,
) : RecyclerView.Adapter<OnboardingSlideAdapter.SlideViewHolder>() {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): SlideViewHolder {
        val view = LayoutInflater.from(parent.context)
            .inflate(R.layout.item_intro_slide, parent, false)
        return SlideViewHolder(view)
    }

    override fun onBindViewHolder(holder: SlideViewHolder, position: Int) {
        holder.bind(items[position])
    }

    override fun getItemCount(): Int = items.size

    class SlideViewHolder(itemView: View) : RecyclerView.ViewHolder(itemView) {
        private val image: ImageView = itemView.findViewById(R.id.slideImage)
        private val title: TextView = itemView.findViewById(R.id.slideTitle)
        private val description: TextView = itemView.findViewById(R.id.slideDescription)

        fun bind(item: OnboardingSlide) {
            image.setImageResource(item.iconResId)
            title.text = item.title
            description.text = item.description
        }
    }
}
